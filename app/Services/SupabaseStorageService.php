<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SupabaseStorageService
{
    protected ?string $url;
    protected ?string $serviceRoleKey;
    protected string $defaultBucket;

    public function __construct()
    {
        $this->url = rtrim(config('services.supabase.url', env('SUPABASE_URL', '')), '/');
        $this->serviceRoleKey = config('services.supabase.service_role_key', env('SUPABASE_SERVICE_ROLE_KEY', ''));
        $this->defaultBucket = config('services.supabase.storage_bucket', env('SUPABASE_STORAGE_BUCKET', 'captures'));
    }

    /**
     * Check if Supabase Storage credentials are configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->url) && !empty($this->serviceRoleKey);
    }

    /**
     * Upload an uploaded file or raw binary data to Supabase Storage.
     *
     * @param  \Illuminate\Http\UploadedFile|string  $file
     * @param  string|null  $filename
     * @param  string|null  $bucket
     * @return string  The public CDN URL or relative storage path
     */
    public function upload(UploadedFile|string $file, ?string $filename = null, ?string $bucket = null): string
    {
        $bucket = $bucket ?: $this->defaultBucket;

        if ($file instanceof UploadedFile) {
            $contents = file_get_contents($file->getRealPath());
            $mimeType = $file->getMimeType() ?: 'image/jpeg';
            $extension = $file->getClientOriginalExtension() ?: 'jpg';
        } else {
            $contents = $file;
            $mimeType = 'image/jpeg';
            $extension = 'jpg';
        }

        if (!$filename) {
            $filename = date('Y-m-d') . '/' . date('His') . '_' . Str::random(10) . '.' . $extension;
        } else {
            $filename = ltrim($filename, '/');
        }

        // Try direct Supabase Cloud Storage REST upload
        if ($this->isConfigured()) {
            try {
                $endpoint = "{$this->url}/storage/v1/object/{$bucket}/{$filename}";

                $response = Http::withoutVerifying()
                    ->withHeaders([
                        'Authorization' => "Bearer {$this->serviceRoleKey}",
                        'apikey'        => $this->serviceRoleKey,
                        'Content-Type'  => $mimeType,
                        'x-upsert'      => 'true',
                    ])
                    ->withBody($contents, $mimeType)
                    ->post($endpoint);

                if ($response->successful()) {
                    $publicUrl = "{$this->url}/storage/v1/object/public/{$bucket}/{$filename}";
                    Log::info("Supabase Storage: File successfully uploaded to {$publicUrl}");
                    return $publicUrl;
                }

                Log::warning("Supabase Storage upload returned {$response->status()}: " . $response->body() . ". Falling back to local storage.");
            } catch (\Throwable $e) {
                Log::error("Supabase Storage upload exception: " . $e->getMessage() . ". Falling back to local storage.");
            }
        }

        // Fallback: Save to configured Laravel disk (local/public/s3)
        if ($file instanceof UploadedFile) {
            return $file->store('captures');
        }

        Storage::disk('public')->put($filename, $contents);
        return $filename;
    }

    /**
     * Delete an object from Supabase Storage.
     */
    public function delete(string $path, ?string $bucket = null): bool
    {
        $bucket = $bucket ?: $this->defaultBucket;

        // If full URL was provided, strip the base public URL to get the relative object path
        $publicPrefix = "{$this->url}/storage/v1/object/public/{$bucket}/";
        if (str_starts_with($path, $publicPrefix)) {
            $path = substr($path, strlen($publicPrefix));
        }

        if ($this->isConfigured()) {
            try {
                $response = Http::withoutVerifying()->withHeaders([
                    'Authorization' => "Bearer {$this->serviceRoleKey}",
                    'apikey'        => $this->serviceRoleKey,
                ])->delete("{$this->url}/storage/v1/object/{$bucket}/{$path}");

                return $response->successful();
            } catch (\Throwable $e) {
                Log::error("Supabase Storage delete failed: " . $e->getMessage());
            }
        }

        return Storage::disk('public')->delete($path);
    }
}
