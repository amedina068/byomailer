<?php

namespace Tests\Feature;

use App\Http\Resources\EmailIdentityResource;
use App\Models\EmailIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class EmailIdentityReplyToTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_set_reply_to(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $this->actingAs($user)->putJson(route('identities.update-reply-to', $identity), [
            'reply_to_email' => 'support@example.com',
            'reply_to_name' => 'Support Team',
        ])->assertOk()->assertJson(['success' => true]);

        $identity->refresh();
        $this->assertSame('support@example.com', $identity->reply_to_email);
        $this->assertSame('Support Team', $identity->reply_to_name);
    }

    public function test_empty_email_and_name_clear_both_fields(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create([
            'reply_to_email' => 'support@example.com',
            'reply_to_name' => 'Support Team',
        ]);

        $this->actingAs($user)->putJson(route('identities.update-reply-to', $identity), [
            'reply_to_email' => '',
            'reply_to_name' => '',
        ])->assertOk();

        $identity->refresh();
        $this->assertNull($identity->reply_to_email);
        $this->assertNull($identity->reply_to_name);
    }

    public function test_name_without_email_is_rejected(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create([
            'reply_to_email' => 'support@example.com',
            'reply_to_name' => 'Support Team',
        ]);

        $this->actingAs($user)->putJson(route('identities.update-reply-to', $identity), [
            'reply_to_email' => '',
            'reply_to_name' => 'Leftover Name',
        ])->assertStatus(422)->assertJsonValidationErrors([
            'reply_to_email' => 'Add an email address to use a Reply-To name.',
        ]);

        $this->assertSame('support@example.com', $identity->fresh()->reply_to_email);
    }

    public function test_name_with_line_break_is_rejected(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        foreach (["Support\r\nBcc: x@evil.test", "Support\nTeam", "Support\0Team"] as $name) {
            $this->actingAs($user)->putJson(route('identities.update-reply-to', $identity), [
                'reply_to_email' => 'support@example.com',
                'reply_to_name' => $name,
            ])->assertStatus(422)->assertJsonValidationErrors([
                'reply_to_name' => 'The name cannot contain line breaks.',
            ]);
        }

        $this->assertNull($identity->fresh()->reply_to_email);
    }

    public function test_email_with_line_break_is_rejected(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $this->actingAs($user)->putJson(route('identities.update-reply-to', $identity), [
            'reply_to_email' => "support@example.com\r\nBcc: x@evil.test",
        ])->assertStatus(422)->assertJsonValidationErrors('reply_to_email');
    }

    public function test_invalid_and_too_long_values_are_rejected(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $this->actingAs($user)->putJson(route('identities.update-reply-to', $identity), [
            'reply_to_email' => 'not-an-email',
            'reply_to_name' => str_repeat('a', 256),
        ])->assertStatus(422)->assertJsonValidationErrors(['reply_to_email', 'reply_to_name']);
    }

    public function test_user_cannot_update_another_clients_identity(): void
    {
        $owner = User::factory()->create();
        $identity = EmailIdentity::factory()->for($owner)->create();

        $this->actingAs(User::factory()->create())->putJson(route('identities.update-reply-to', $identity), [
            'reply_to_email' => 'support@example.com',
        ])->assertNotFound();

        $this->assertNull($identity->fresh()->reply_to_email);
    }

    public function test_resource_exposes_reply_to_fields(): void
    {
        $identity = EmailIdentity::factory()->create([
            'reply_to_email' => 'support@example.com',
            'reply_to_name' => 'Support Team',
        ]);

        $data = (new EmailIdentityResource($identity))->toArray(Request::create('/'));

        $this->assertSame('support@example.com', $data['reply_to_email']);
        $this->assertSame('Support Team', $data['reply_to_name']);
    }
}
