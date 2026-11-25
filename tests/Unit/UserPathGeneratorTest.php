<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\UserStorageSetting;
use App\Support\Media\UserPathGenerator;
use Mockery;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class UserPathGeneratorTest extends TestCase
{
    private function makeMedia(mixed $model, int $id = 1, string $disk = 'public'): Media
    {
        $media = Mockery::mock(Media::class)->makePartial();
        $media->shouldReceive('getKey')->andReturn($id);
        $media->model = $model;
        $media->disk = $disk;

        return $media;
    }

    public function test_default_disk_media_uses_spatie_default_path(): void
    {
        $user = Mockery::mock(User::class)->makePartial();

        $media = $this->makeMedia($user, 7, 'public');

        $generator = new UserPathGenerator;

        $this->assertEquals('7/', $generator->getPath($media));
        $this->assertEquals('7/conversions/', $generator->getPathForConversions($media));
        $this->assertEquals('7/responsive-images/', $generator->getPathForResponsiveImages($media));
    }

    public function test_user_byo_disk_media_uses_custom_path_with_verified_storage(): void
    {
        $setting = new UserStorageSetting;
        $setting->user_id = 42;
        $setting->path_prefix = 'byomailer/42';
        $setting->is_verified = true;

        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('getAttribute')->with('storageSetting')->andReturn($setting);
        $user->shouldReceive('getKey')->andReturn(42);

        $media = $this->makeMedia($user, 5, 'user-media-42');

        $generator = new UserPathGenerator;

        $this->assertEquals('byomailer/42/emails/5/', $generator->getPath($media));
        $this->assertEquals('byomailer/42/emails/5/conversions/', $generator->getPathForConversions($media));
        $this->assertEquals('byomailer/42/emails/5/responsive-images/', $generator->getPathForResponsiveImages($media));
    }

    public function test_path_prefix_placeholder_is_resolved_on_byo_disk(): void
    {
        $setting = new UserStorageSetting;
        $setting->user_id = 99;
        $setting->path_prefix = 'byomailer/{user_id}';
        $setting->is_verified = true;

        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('getAttribute')->with('storageSetting')->andReturn($setting);
        $user->shouldReceive('getKey')->andReturn(99);

        $media = $this->makeMedia($user, 3, 'user-media-99');

        $generator = new UserPathGenerator;

        $this->assertEquals('byomailer/99/emails/3/', $generator->getPath($media));
    }

    public function test_null_model_on_default_disk_falls_back_to_id_path(): void
    {
        $media = $this->makeMedia(null, 10, 'public');

        $generator = new UserPathGenerator;

        $this->assertEquals('10/', $generator->getPath($media));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
