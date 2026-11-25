<?php

namespace App\Support\Media;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

class UserPathGenerator implements PathGenerator
{
    public function getPath(Media $media): string
    {
        return $this->getBasePath($media).'/';
    }

    public function getPathForConversions(Media $media): string
    {
        return $this->getBasePath($media).'/conversions/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->getBasePath($media).'/responsive-images/';
    }

    private function getBasePath(Media $media): string
    {
        if (str_starts_with($media->disk, 'user-media-')) {
            $prefix = $this->resolvePathPrefix($media);

            return "{$prefix}/emails/{$media->getKey()}";
        }

        return (string) $media->getKey();
    }

    private function resolvePathPrefix(Media $media): string
    {
        $user = $media->model;

        $setting = $user->storageSetting;

        if ($setting?->is_verified) {
            return $setting->resolvedPathPrefix();
        }

        return 'byomailer/'.$user->id;
    }
}
