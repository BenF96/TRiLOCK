<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Last moment (UTC) before the APP_TIMEZONE=Asia/Singapore deploy on 2026-09-17: the
     * last UTC-era row is at 05:35 UTC, the first Singapore-era row was written at 06:37
     * UTC but stored as "14:37+00". No row exists in between, so this cutoff is exact.
     */
    private const TIMEZONE_SWITCH_CUTOFF_UTC = '2026-09-17 10:00:00+00';

    /**
     * The Supabase tables were created with TIMESTAMPTZ, but Laravel writes naive wall-clock
     * strings in APP_TIMEZONE and the Postgres session runs in UTC. Since the switch to
     * Asia/Singapore every timestamp was stored 8h in the future - so Device::isOnline()
     * kept an unplugged device "online" for 8 hours and "last seen" read "8 hours from now".
     *
     * Converting to TIMESTAMP (no time zone) - what Laravel's own migrations create on
     * Postgres - stores exactly what Laravel writes, independent of the session time zone
     * (which a transaction-mode pooler can't reliably pin). Existing rows are corrected:
     * UTC-era rows are converted to app-local time, Singapore-era rows already hold it.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $tz     = config('app.timezone');
        $cutoff = self::TIMEZONE_SWITCH_CUTOFF_UTC;

        foreach ($this->columns('timestamp with time zone') as $col) {
            $c = "\"{$col->column_name}\"";

            DB::statement("ALTER TABLE \"{$col->table_name}\" ALTER COLUMN {$c} TYPE timestamp(0) without time zone USING (
                CASE WHEN {$c} < '{$cutoff}' THEN {$c} AT TIME ZONE '{$tz}' ELSE {$c} AT TIME ZONE 'UTC' END
            )");

            if ($col->column_default !== null) {
                DB::statement("ALTER TABLE \"{$col->table_name}\" ALTER COLUMN {$c} SET DEFAULT (CURRENT_TIMESTAMP AT TIME ZONE '{$tz}')");
            }
        }

        // access_logs.date/time were written from now() alongside created_at, so UTC-era rows
        // carry UTC dates/times too - realign them with the corrected created_at.
        DB::statement("UPDATE access_logs SET date = created_at::date, time = created_at::time(0)
            WHERE created_at < ('{$cutoff}'::timestamptz AT TIME ZONE '{$tz}')");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $tz = config('app.timezone');

        foreach (['access_logs', 'access_pins', 'devices', 'door_logs', 'enrollment_logs', 'fingerprints', 'password_reset_tokens', 'rfid_cards', 'settings', 'users'] as $table) {
            foreach ($this->columns('timestamp without time zone', $table) as $col) {
                $c = "\"{$col->column_name}\"";
                DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN {$c} TYPE timestamptz USING ({$c} AT TIME ZONE '{$tz}')");
                if ($col->column_default !== null) {
                    DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN {$c} SET DEFAULT CURRENT_TIMESTAMP");
                }
            }
        }
    }

    private function columns(string $type, ?string $table = null): array
    {
        return DB::select(
            'SELECT table_name, column_name, column_default FROM information_schema.columns
             WHERE table_schema = current_schema() AND data_type = ?' . ($table ? ' AND table_name = ?' : ''),
            $table ? [$type, $table] : [$type]
        );
    }
};
