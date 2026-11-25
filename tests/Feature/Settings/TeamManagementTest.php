<?php

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Mail\UserInviteMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_team_page(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        User::factory()->for($admin->client)->create(['role' => UserRole::Viewer]);

        $this->actingAs($admin)
            ->get(route('team.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Team')
                ->where('canManageTeam', true)
                ->has('teamMembers', 2)
            );
    }

    public function test_admin_can_invite_new_member(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this
            ->actingAs($admin)
            ->post(route('team.store'), [
                'name' => 'Invited User',
                'email' => 'invitee@example.com',
                'role' => UserRole::Editor->value,
            ]);

        $response->assertRedirect(route('team.index'));

        $invitee = User::where('email', 'invitee@example.com')->first();

        $this->assertNotNull($invitee);
        $this->assertSame(UserRole::Editor->value, $invitee->getRawOriginal('role'));
        $this->assertNotNull($invitee->invite_expires_at);

        Mail::assertSent(UserInviteMail::class, fn (UserInviteMail $mail): bool => $mail->invitee->is($invitee));
    }

    public function test_non_admin_cannot_manage_team(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);

        $this
            ->actingAs($viewer)
            ->post(route('team.store'), [
                'name' => 'Someone',
                'email' => 'someone@example.com',
                'role' => UserRole::Viewer->value,
            ])
            ->assertForbidden();
    }

    public function test_user_with_pending_invite_is_blocked_from_dashboard(): void
    {
        $invited = User::factory()->create([
            'role' => UserRole::Viewer,
            'invite_expires_at' => now()->addDay(),
            'email_verified_at' => null,
        ]);

        $this
            ->actingAs($invited)
            ->get(route('dashboard'))
            ->assertForbidden();
    }

    public function test_invited_user_can_complete_invitation(): void
    {
        $invited = User::factory()->create([
            'role' => UserRole::Viewer,
            'invite_expires_at' => now()->addDay(),
            'email_verified_at' => null,
        ]);

        $signedUrl = URL::temporarySignedRoute('invites.show', now()->addDay(), ['user' => $invited->uuid]);
        parse_str(parse_url($signedUrl, PHP_URL_QUERY) ?? '', $signatureParams);
        $queryString = http_build_query($signatureParams);

        $response = $this->post(route('invites.store', ['user' => $invited->uuid]).'?'.$queryString, [
            'email' => $invited->email,
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ]);

        $response->assertRedirect(route('dashboard'));

        $invited->refresh();

        $this->assertSame(UserRole::Viewer->value, $invited->getRawOriginal('role'));
        $this->assertNull($invited->invite_expires_at);
        $this->assertNotNull($invited->email_verified_at);
        $this->assertAuthenticatedAs($invited);
    }

    public function test_expired_invitation_returns_error(): void
    {
        $invited = User::factory()->create([
            'role' => UserRole::Viewer,
            'invite_expires_at' => now()->subMinute(),
            'email_verified_at' => null,
        ]);

        $signedUrl = URL::temporarySignedRoute('invites.show', now()->addMinutes(10), ['user' => $invited->uuid]);

        $this
            ->get($signedUrl)
            ->assertStatus(410)
            ->assertInertia(fn (Assert $page) => $page->component('invites/Expired'));
    }

    public function test_admin_can_archive_team_member(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $member = User::factory()->for($admin->client)->create(['role' => UserRole::Viewer]);

        $this
            ->actingAs($admin)
            ->post(route('team.archive', $member))
            ->assertRedirect(route('team.index'));

        $member->refresh();

        $this->assertNotNull($member->archived_at);

        $this
            ->actingAs($member)
            ->get(route('dashboard'))
            ->assertForbidden();
    }

    public function test_archived_user_cannot_access_dashboard(): void
    {
        $archived = User::factory()->create([
            'role' => UserRole::Viewer,
            'archived_at' => now(),
        ]);

        $this
            ->actingAs($archived)
            ->get(route('dashboard'))
            ->assertForbidden();
    }

    public function test_admin_can_unarchive_member(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $member = User::factory()->for($admin->client)->create([
            'role' => UserRole::Editor,
            'archived_at' => now(),
        ]);

        $this
            ->actingAs($admin)
            ->delete(route('team.unarchive', $member))
            ->assertRedirect(route('team.index'));

        $member->refresh();

        $this->assertNull($member->archived_at);

        $this
            ->actingAs($member)
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_admin_can_invite_many_users(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);

        User::factory()->for($admin->client)->count(5)->create(['role' => UserRole::Viewer]);

        $response = $this
            ->actingAs($admin)
            ->post(route('team.store'), [
                'name' => 'Another User',
                'email' => 'another@example.com',
                'role' => UserRole::Editor->value,
            ]);

        $response->assertRedirect(route('team.index'));
        $response->assertSessionHasNoErrors();

        Mail::assertSent(UserInviteMail::class);
    }

    public function test_admin_can_change_the_role_of_a_member(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $member = User::factory()->for($admin->client)->create(['role' => UserRole::Viewer]);

        $this->actingAs($admin)
            ->patch(route('team.update', $member), ['role' => UserRole::Editor->value])
            ->assertRedirect(route('team.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(UserRole::Editor, $member->fresh()->role);
    }

    public function test_an_editor_cannot_change_a_role(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $editor = User::factory()->for($admin->client)->create(['role' => UserRole::Editor]);

        $this->actingAs($editor)
            ->patch(route('team.update', $editor), ['role' => UserRole::Admin->value])
            ->assertForbidden();

        $this->assertSame(UserRole::Editor, $editor->fresh()->role);
    }

    public function test_an_admin_cannot_change_the_role_of_a_user_in_another_client(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $stranger = User::factory()->create(['role' => UserRole::Viewer]);

        $this->actingAs($admin)
            ->patch(route('team.update', $stranger), ['role' => UserRole::Admin->value])
            ->assertNotFound();

        $this->assertSame(UserRole::Viewer, $stranger->fresh()->role);
    }

    public function test_the_last_admin_cannot_demote_itself(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        User::factory()->for($admin->client)->create(['role' => UserRole::Editor]);

        $this->actingAs($admin)
            ->patch(route('team.update', $admin), ['role' => UserRole::Editor->value])
            ->assertSessionHasErrors(['role' => 'Your team must have at least one admin.']);

        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
    }

    public function test_a_pending_invitation_cannot_get_a_new_role(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $invitee = User::factory()->for($admin->client)->create([
            'role' => UserRole::Invited,
            'invite_expires_at' => now()->addDay(),
        ]);

        $this->actingAs($admin)
            ->patch(route('team.update', $invitee), ['role' => UserRole::Editor->value])
            ->assertSessionHasErrors(['role' => 'Pending invitations cannot be reassigned.']);

        $this->assertSame(UserRole::Invited, $invitee->fresh()->role);
    }

    public function test_the_invited_role_cannot_be_assigned(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $member = User::factory()->for($admin->client)->create(['role' => UserRole::Viewer]);

        $this->actingAs($admin)
            ->patch(route('team.update', $member), ['role' => UserRole::Invited->value])
            ->assertSessionHasErrors(['role' => 'The selected role is invalid.']);

        $this->assertSame(UserRole::Viewer, $member->fresh()->role);
    }
}
