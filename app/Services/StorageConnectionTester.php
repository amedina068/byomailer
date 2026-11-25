<?php

namespace App\Services;

use App\Exceptions\SsrfException;
use App\Models\UserStorageSetting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class StorageConnectionTester
{
    /**
     * @return array{success: bool, error?: string}
     */
    public function test(UserStorageSetting $settings): array
    {
        try {
            $httpOptions = UserFilesystemManager::endpointHttpOptions($settings);
        } catch (SsrfException) {
            return ['success' => false, 'error' => 'The storage endpoint must be a public https URL.'];
        }

        $diskName = 'byomailer-test-'.Str::random(8);

        config([
            "filesystems.disks.{$diskName}" => [
                'driver' => 's3',
                'key' => $settings->access_key_id,
                'secret' => $settings->secret_access_key,
                'region' => $settings->region,
                'bucket' => $settings->bucket,
                'endpoint' => $settings->endpoint ?: null,
                'use_path_style_endpoint' => true,
                'http' => $httpOptions,
                'throw' => true,
                'report' => false,
            ],
        ]);

        $disk = Storage::disk($diskName);
        $testPath = $settings->resolvedPathPrefix().'/.byomailer-connection-test';
        $testContent = 'byomailer-connection-test-'.now()->timestamp;

        try {
            $disk->put($testPath, $testContent);
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => 'Write failed: '.$e->getMessage().'. Ensure s3:PutObject permission is granted on the bucket.',
            ];
        }

        try {
            $retrieved = $disk->get($testPath);
            if ($retrieved !== $testContent) {
                return ['success' => false, 'error' => 'Read verification failed: content mismatch. Ensure the bucket is readable.'];
            }
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => 'Read failed: '.$e->getMessage().'. Ensure s3:GetObject permission is granted on the bucket.',
            ];
        }

        try {
            $disk->delete($testPath);
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => 'Delete failed: '.$e->getMessage().'. Ensure s3:DeleteObject permission is granted on the bucket.',
            ];
        }

        return ['success' => true];
    }
}
