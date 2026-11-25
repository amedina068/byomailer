<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserStorageSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EditorImageControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_upload_an_image(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('test.jpg', 800, 600);

        $response = $this->actingAs($user)->postJson(route('editor.images.store'), [
            'image' => $file,
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['url']);
        $this->assertStringContainsString('editor-images', $response->json('url'));

        $files = Storage::disk('public')->files('editor-images');
        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.jpg', $files[0]);
    }

    public function test_an_upload_uses_the_custom_storage_of_the_user(): void
    {
        $user = User::factory()->create();
        UserStorageSetting::factory()->verified()->create(['user_id' => $user->id, 'endpoint' => null]);
        Storage::fake('public');
        Storage::fake("user-media-{$user->id}");

        $this->actingAs($user)->postJson(route('editor.images.store'), [
            'image' => UploadedFile::fake()->image('photo.jpg', 400, 300),
        ])->assertOk();

        $this->assertCount(1, Storage::disk("user-media-{$user->id}")->files('editor-images'));
        $this->assertCount(0, Storage::disk('public')->files('editor-images'));
    }

    public function test_user_can_upload_png_image(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('test.png', 400, 300);

        $response = $this->actingAs($user)->postJson(route('editor.images.store'), [
            'image' => $file,
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['url']);
    }

    public function test_user_can_upload_gif_image(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('test.gif', 200, 200);

        $response = $this->actingAs($user)->postJson(route('editor.images.store'), [
            'image' => $file,
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['url']);
    }

    public function test_user_can_upload_webp_image(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('test.webp', 100, 'image/webp');

        $response = $this->actingAs($user)->postJson(route('editor.images.store'), [
            'image' => $file,
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['url']);
    }

    public function test_guest_cannot_upload_images(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->image('test.jpg', 800, 600);

        $response = $this->postJson(route('editor.images.store'), [
            'image' => $file,
        ]);

        $response->assertUnauthorized();
    }

    public function test_image_is_required(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('editor.images.store'), []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('image');
    }

    public function test_file_must_be_an_image(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->actingAs($user)->postJson(route('editor.images.store'), [
            'image' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('image');
    }

    public function test_image_must_not_exceed_max_size(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('large.jpg')->size(6000);

        $response = $this->actingAs($user)->postJson(route('editor.images.store'), [
            'image' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('image');
    }

    public function test_image_must_be_allowed_mime_type(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('test.bmp', 100, 'image/bmp');

        $response = $this->actingAs($user)->postJson(route('editor.images.store'), [
            'image' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('image');
    }

    public function test_uploaded_image_has_unique_filename(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $file1 = UploadedFile::fake()->image('same-name.jpg', 100, 100);
        $file2 = UploadedFile::fake()->image('same-name.jpg', 100, 100);

        $response1 = $this->actingAs($user)->postJson(route('editor.images.store'), [
            'image' => $file1,
        ]);

        $response2 = $this->actingAs($user)->postJson(route('editor.images.store'), [
            'image' => $file2,
        ]);

        $response1->assertOk();
        $response2->assertOk();

        $url1 = $response1->json('url');
        $url2 = $response2->json('url');

        $this->assertNotEquals($url1, $url2);
    }

    public function test_gif_polyglot_named_html_is_stored_with_the_detected_image_extension(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('editor.images.store'), [
            'image' => $this->gifPolyglotNamedHtml(),
        ]);

        $response->assertOk();
        $this->assertStringEndsWith('.gif', $response->json('url'));
        $files = Storage::disk('public')->files('editor-images');
        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.gif', $files[0]);
    }

    private function gifPolyglotNamedHtml(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'polyglot');
        file_put_contents($path, base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7').'<html><script>alert(1)</script></html>');

        return new UploadedFile($path, 'evil.html', null, null, true);
    }
}
