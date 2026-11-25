<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\UserStorageSetting;
use App\Support\Media\UserPathGenerator;
use App\Support\Media\UserUrlGenerator;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class UserUrlGeneratorTest extends TestCase
{
    private function makeMedia(mixed $model, string $disk = 'public'): Media
    {
        $media = Mockery::mock(Media::class)->makePartial();
        $media->shouldReceive('getKey')->andReturn(1);
        $media->model = $model;
        $media->disk = $disk;
        $media->file_name = 'photo.jpg';
        $media->updated_at = now();

        return $media;
    }

    private function makeGenerator(Media $media): UserUrlGenerator
    {
        $config = $this->app->make(Config::class);
        $generator = new UserUrlGenerator($config);

        $generator->setMedia($media);
        $generator->setPathGenerator(new UserPathGenerator);

        return $generator;
    }

    public function test_default_disk_media_always_falls_back_regardless_of_byo_setting(): void
    {
        Storage::fake('public');

        $setting = new UserStorageSetting;
        $setting->user_id = 1;
        $setting->path_prefix = 'byomailer/1';
        $setting->is_verified = true;
        $setting->public_base_url = 'https://cdn.example.com';

        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('getAttribute')->with('storageSetting')->andReturn($setting);

        // Media is on the default public disk — must NOT use CDN
        $media = $this->makeMedia($user, 'public');
        $generator = $this->makeGenerator($media);

        $url = $generator->getUrl();

        $this->assertStringNotContainsString('cdn.example.com', $url);
    }

    public function test_user_byo_disk_media_uses_custom_cdn_url(): void
    {
        Storage::fake('public');

        $setting = new UserStorageSetting;
        $setting->user_id = 2;
        $setting->path_prefix = 'byomailer/2';
        $setting->is_verified = true;
        $setting->public_base_url = 'https://cdn.example.com';

        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('getAttribute')->with('storageSetting')->andReturn($setting);
        $user->shouldReceive('getKey')->andReturn(2);

        // Media is on the user's BYO disk — must use CDN
        $media = $this->makeMedia($user, 'user-media-2');
        $generator = $this->makeGenerator($media);

        $url = $generator->getUrl();

        $this->assertStringStartsWith('https://cdn.example.com/', $url);
        $this->assertStringContainsString('byomailer/2/emails/1/photo.jpg', $url);
    }

    public function test_trailing_slash_in_base_url_is_normalized(): void
    {
        Storage::fake('public');

        $setting = new UserStorageSetting;
        $setting->user_id = 3;
        $setting->path_prefix = 'byomailer/3';
        $setting->is_verified = true;
        $setting->public_base_url = 'https://cdn.example.com/';

        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('getAttribute')->with('storageSetting')->andReturn($setting);
        $user->shouldReceive('getKey')->andReturn(3);

        $media = $this->makeMedia($user, 'user-media-3');
        $generator = $this->makeGenerator($media);

        $url = $generator->getUrl();

        // No double slashes after the scheme
        $this->assertStringNotContainsString('//', substr($url, 8));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
