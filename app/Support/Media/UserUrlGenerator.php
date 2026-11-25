<?php

namespace App\Support\Media;

use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator;

class UserUrlGenerator extends DefaultUrlGenerator
{
    public function getUrl(): string
    {
        if (! str_starts_with($this->media->disk, 'user-media-')) {
            return parent::getUrl();
        }

        $user = $this->media->model;

        if (! $user->storageSetting?->is_verified) {
            return parent::getUrl();
        }

        $baseUrl = rtrim($user->storageSetting->public_base_url, '/');
        $relativePath = ltrim($this->getPathRelativeToRoot(), '/');

        return $this->versionUrl("{$baseUrl}/{$relativePath}");
    }
}
