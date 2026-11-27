<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GalleryImageControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_fetch_empty_gallery(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson(route('gallery.images.index'));

        $response->assertOk();
        $response->assertJson(['images' => []]);
    }

    public function test_authenticated_user_can_fetch_their_gallery_images(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('photo.jpg', 400, 300);
        $user->addMedia($file)->toMediaCollection('gallery');

        $response = $this->actingAs($user)->getJson(route('gallery.images.index'));

        $response->assertOk();
        $response->assertJsonCount(1, 'images');
        $response->assertJsonStructure([
            'images' => [
                ['uuid', 'name', 'url', 'created_at'],
            ],
        ]);
    }

    public function test_gallery_only_returns_current_users_images(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $fileA = UploadedFile::fake()->image('user-a.jpg', 100, 100);
        $userA->addMedia($fileA)->toMediaCollection('gallery');

        $fileB = UploadedFile::fake()->image('user-b.jpg', 100, 100);
        $userB->addMedia($fileB)->toMediaCollection('gallery');

        $response = $this->actingAs($userA)->getJson(route('gallery.images.index'));

        $response->assertOk();
        $response->assertJsonCount(1, 'images');
        $this->assertStringContainsString('user-a', $response->json('images.0.name'));
    }

    public function test_guest_cannot_fetch_gallery(): void
    {
        $response = $this->getJson(route('gallery.images.index'));

        $response->assertUnauthorized();
    }

    public function test_authenticated_user_can_upload_image_to_gallery(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('test.jpg', 800, 600);

        $response = $this->actingAs($user)->postJson(route('gallery.images.store'), [
            'image' => $file,
        ]);

        $response->assertCreated();
        $response->assertJsonStructure(['uuid', 'name', 'url', 'created_at']);

        $this->assertCount(1, $user->getMedia('gallery'));
    }

    public function test_guest_cannot_upload_to_gallery(): void
    {
        $file = UploadedFile::fake()->image('test.jpg', 100, 100);

        $response = $this->postJson(route('gallery.images.store'), [
            'image' => $file,
        ]);

        $response->assertUnauthorized();
    }

    public function test_image_field_is_required_for_upload(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('gallery.images.store'), []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('image');
    }

    public function test_uploaded_file_must_be_an_image(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->actingAs($user)->postJson(route('gallery.images.store'), [
            'image' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('image');
    }

    public function test_uploaded_image_must_not_exceed_max_size(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('large.jpg')->size(6000);

        $response = $this->actingAs($user)->postJson(route('gallery.images.store'), [
            'image' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('image');
    }

    public function test_uploaded_image_must_be_allowed_mime_type(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('test.bmp', 100, 'image/bmp');

        $response = $this->actingAs($user)->postJson(route('gallery.images.store'), [
            'image' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('image');
    }

    public function test_uploaded_images_are_stored_per_user(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $file = UploadedFile::fake()->image('test.jpg', 100, 100);

        $this->actingAs($userA)->postJson(route('gallery.images.store'), [
            'image' => $file,
        ]);

        $this->assertCount(1, $userA->getMedia('gallery'));
        $this->assertCount(0, $userB->getMedia('gallery'));
    }

    public function test_gallery_images_are_returned_with_correct_structure(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('my-photo.jpg', 200, 200);
        $user->addMedia($file)->toMediaCollection('gallery');

        $response = $this->actingAs($user)->getJson(route('gallery.images.index'));

        $image = $response->json('images.0');

        $this->assertArrayHasKey('uuid', $image);
        $this->assertArrayHasKey('name', $image);
        $this->assertArrayHasKey('url', $image);
        $this->assertArrayHasKey('created_at', $image);
        $this->assertNotEmpty($image['uuid']);
        $this->assertNotEmpty($image['url']);
    }

    public function test_newly_uploaded_image_appears_in_gallery(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('new-upload.png', 300, 300);

        $uploadResponse = $this->actingAs($user)->postJson(route('gallery.images.store'), [
            'image' => $file,
        ]);

        $uploadedUuid = $uploadResponse->json('uuid');

        $galleryResponse = $this->actingAs($user)->getJson(route('gallery.images.index'));

        $galleryUuids = collect($galleryResponse->json('images'))->pluck('uuid')->toArray();

        $this->assertContains($uploadedUuid, $galleryUuids);
    }

    public function test_user_can_upload_png_image(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('test.png', 400, 300);

        $response = $this->actingAs($user)->postJson(route('gallery.images.store'), [
            'image' => $file,
        ]);

        $response->assertCreated();
    }

    public function test_gif_polyglot_named_html_is_stored_with_a_random_name_and_the_detected_extension(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $path = $this->temporaryFile('polyglot');
        file_put_contents($path, base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7').'<html><script>alert(1)</script></html>');
        $file = new UploadedFile($path, 'evil.html', null, null, true);

        $response = $this->actingAs($user)->postJson(route('gallery.images.store'), ['image' => $file]);

        $response->assertCreated();
        $this->assertStringEndsWith('.gif', $response->json('url'));
        $this->assertStringNotContainsString('evil', $response->json('url'));
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.gif$/', $user->getFirstMedia('gallery')->file_name);
    }
}
