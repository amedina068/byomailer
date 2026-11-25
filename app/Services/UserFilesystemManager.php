<?php

namespace App\Services;

use App\Exceptions\SsrfException;
use App\Models\User;
use App\Models\UserStorageSetting;

class UserFilesystemManager
{
    private array $registered = [];

    public function diskFor(User $user): string
    {
        if (! $this->hasCustomStorage($user)) {
            return config('media-library.disk_name');
        }

        $diskName = "user-media-{$user->id}";

        if (! in_array($diskName, $this->registered)) {
            $settings = $user->storageSetting;

            config([
                "filesystems.disks.{$diskName}" => [
                    'driver' => 's3',
                    'key' => $settings->access_key_id,
                    'secret' => $settings->secret_access_key,
                    'region' => $settings->region,
                    'bucket' => $settings->bucket,
                    'endpoint' => $settings->endpoint ?: null,
                    'use_path_style_endpoint' => true,
                    'http' => self::endpointHttpOptions($settings),
                    'throw' => false,
                    'report' => false,
                ],
            ]);

            $this->registered[] = $diskName;
        }

        return $diskName;
    }

    /**
     * The HTTP options of the S3 client. A custom endpoint must be a public
     * https URL each time that it is used, and the client connects to the IP
     * that was checked.
     *
     * @return array<string, mixed>
     *
     * @throws SsrfException
     */
    public static function endpointHttpOptions(UserStorageSetting $settings): array
    {
        if (blank($settings->endpoint)) {
            return [];
        }

        return ['curl' => app(SsrfGuard::class)->pinnedCurlOptions($settings->endpoint)];
    }

    public function hasCustomStorage(User $user): bool
    {
        return $user->storageSetting?->is_verified === true;
    }
}
