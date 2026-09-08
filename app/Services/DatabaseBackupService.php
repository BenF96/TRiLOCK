<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Shells out to the Postgres client tools (pg_dump/psql) to create and
 * restore real database backups. Both directions use the app's own DB
 * connection config as the single source of truth (config/database.php),
 * and pass the password via a PGPASSWORD env var rather than a CLI argument
 * so it never shows up in a process listing.
 */
class DatabaseBackupService
{
    private const BACKUP_DIR = 'backups';
    private const FILENAME_PATTERN = '/^trilock_\d{4}_\d{2}_\d{2}_\d{6}\.sql$/';
    private const PROCESS_TIMEOUT = 300;

    /**
     * @return array{ok: bool, filename?: string, error?: string}
     */
    public function backup(): array
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::BACKUP_DIR);

        $filename = 'trilock_' . now()->format('Y_m_d_His') . '.sql';
        $path = $disk->path(self::BACKUP_DIR . '/' . $filename);
        $config = $this->connectionConfig();

        $process = new Process([
            'pg_dump',
            '--no-owner',
            '--no-privileges',
            '--format=plain',
            '--host=' . $config['host'],
            '--port=' . $config['port'],
            '--username=' . $config['username'],
            '--dbname=' . $config['database'],
            '--file=' . $path,
        ]);
        $process->setEnv($this->processEnv($config));
        $process->setTimeout(self::PROCESS_TIMEOUT);

        try {
            $process->mustRun();
        } catch (ProcessExceptionInterface $e) {
            $disk->delete(self::BACKUP_DIR . '/' . $filename);
            $message = $this->describeFailure($e);
            Log::error("DatabaseBackupService: pg_dump failed — {$message}");

            return ['ok' => false, 'error' => $message];
        }

        Log::info("DatabaseBackupService: backup created — {$filename}");

        return ['ok' => true, 'filename' => $filename];
    }

    /**
     * Restores from a backup this system itself created — never an
     * arbitrary uploaded file — and always takes a fresh safety backup of
     * the current state first so the action is reversible.
     *
     * @return array{ok: bool, filename?: string, safety_backup?: string, error?: string}
     */
    public function restore(string $filename): array
    {
        if (!$this->isValidFilename($filename) || !Storage::disk('local')->exists(self::BACKUP_DIR . '/' . $filename)) {
            return ['ok' => false, 'error' => 'Unknown backup file.'];
        }

        $safety = $this->backup();
        if (!$safety['ok']) {
            return ['ok' => false, 'error' => 'Aborted before touching the database: could not create a safety backup first — ' . $safety['error']];
        }

        $path = Storage::disk('local')->path(self::BACKUP_DIR . '/' . $filename);
        $config = $this->connectionConfig();

        $process = new Process([
            'psql',
            '--host=' . $config['host'],
            '--port=' . $config['port'],
            '--username=' . $config['username'],
            '--dbname=' . $config['database'],
            '--file=' . $path,
            '--set=ON_ERROR_STOP=1',
        ]);
        $process->setEnv($this->processEnv($config));
        $process->setTimeout(self::PROCESS_TIMEOUT);

        try {
            $process->mustRun();
        } catch (ProcessExceptionInterface $e) {
            $message = $this->describeFailure($e);
            Log::error("DatabaseBackupService: restore from {$filename} failed — {$message}");

            return ['ok' => false, 'error' => $message, 'safety_backup' => $safety['filename']];
        }

        Log::warning("DatabaseBackupService: restored from {$filename} (safety backup: {$safety['filename']})");

        return ['ok' => true, 'filename' => $filename, 'safety_backup' => $safety['filename']];
    }

    /**
     * @return array<int, array{name: string, size: int, modified_at: Carbon}>
     */
    public function listBackups(): array
    {
        $disk = Storage::disk('local');
        if (!$disk->exists(self::BACKUP_DIR)) {
            return [];
        }

        return collect($disk->files(self::BACKUP_DIR))
            ->filter(fn (string $path) => $this->isValidFilename(basename($path)))
            ->map(fn (string $path) => [
                'name' => basename($path),
                'size' => $disk->size($path),
                'modified_at' => Carbon::createFromTimestamp($disk->lastModified($path)),
            ])
            ->sortByDesc('modified_at')
            ->values()
            ->all();
    }

    public function isValidFilename(string $filename): bool
    {
        return (bool) preg_match(self::FILENAME_PATTERN, $filename);
    }

    public function downloadResponse(string $filename): ?StreamedResponse
    {
        $relative = self::BACKUP_DIR . '/' . $filename;

        if (!$this->isValidFilename($filename) || !Storage::disk('local')->exists($relative)) {
            return null;
        }

        return Storage::disk('local')->download($relative);
    }

    /**
     * @return array{host: string, port: string, database: string, username: string, password: string, sslmode: string}
     */
    public function connectionConfig(): array
    {
        $config = config('database.connections.pgsql');

        return [
            'host'     => (string) $config['host'],
            'port'     => (string) $config['port'],
            'database' => (string) $config['database'],
            'username' => (string) $config['username'],
            'password' => (string) $config['password'],
            'sslmode'  => (string) ($config['sslmode'] ?? 'prefer'),
        ];
    }

    /**
     * @param array{password: string, sslmode: string} $config
     * @return array<string, string>
     */
    private function processEnv(array $config): array
    {
        return [
            'PGPASSWORD' => $config['password'],
            'PGSSLMODE' => $config['sslmode'],
        ];
    }

    private function describeFailure(ProcessExceptionInterface $e): string
    {
        if ($e instanceof ProcessFailedException) {
            $stderr = trim($e->getProcess()->getErrorOutput());
            if ($stderr !== '') {
                return $stderr;
            }
        }

        $message = $e->getMessage();
        if (str_contains($message, 'not found') || str_contains(strtolower($message), 'no such file or directory')) {
            return 'The PostgreSQL client tools (pg_dump/psql) are not installed on this server.';
        }

        return $message;
    }
}
