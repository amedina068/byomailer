<?php

namespace Tests\Feature\Settings;

use App\Actions\DeleteUser;
use App\Enums\UserRole;
use App\Models\Contact;
use App\Models\EmailCredential;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeleteAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_member_who_deletes_the_account_hands_the_team_data_to_an_admin(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $editor = User::factory()->for($admin->client)->create(['role' => UserRole::Editor]);

        $contact = Contact::factory()->for($editor)->create();
        $newsletter = Newsletter::factory()->for($editor)->create();
        $sequence = Sequence::factory()->for($editor)->create();
        $list = MailList::factory()->for($editor)->create();
        $tag = Tag::factory()->for($editor)->create();
        $credential = EmailCredential::factory()->for($editor)->create();

        $this->actingAs($editor)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNull($editor->fresh());

        foreach ([$contact, $newsletter, $sequence, $list, $tag, $credential] as $record) {
            $fresh = $record::withoutGlobalScopes()->find($record->getKey());

            $this->assertNotNull($fresh, $record::class.' was deleted with its author.');
            $this->assertSame($admin->id, $fresh->user_id, $record::class.' was not handed to the admin.');
        }
    }

    public function test_a_viewer_can_delete_the_account_and_keeps_the_team_data(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $viewer = User::factory()->for($admin->client)->create(['role' => UserRole::Viewer]);
        $contact = Contact::factory()->for($viewer)->create();

        $this->actingAs($viewer)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertNull($viewer->fresh());
        $this->assertSame($admin->id, Contact::withoutGlobalScopes()->find($contact->id)?->user_id);
    }

    public function test_an_admin_hands_the_data_to_the_oldest_other_active_admin(): void
    {
        $leaving = User::factory()->create(['role' => UserRole::Admin]);
        $deactivated = User::factory()->for($leaving->client)->create([
            'role' => UserRole::Admin,
            'deactivated_at' => now(),
        ]);
        $successor = User::factory()->for($leaving->client)->create(['role' => UserRole::Admin]);
        User::factory()->for($leaving->client)->create(['role' => UserRole::Admin]);
        $contact = Contact::factory()->for($leaving)->create();

        $this->actingAs($leaving)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertSame($successor->id, Contact::withoutGlobalScopes()->find($contact->id)?->user_id);
        $this->assertNotNull($deactivated->fresh());
    }

    public function test_the_only_admin_of_a_team_cannot_delete_the_account(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        User::factory()->for($admin->client)->create(['role' => UserRole::Editor]);
        $contact = Contact::factory()->for($admin)->create();

        $this->actingAs($admin)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHasErrors([
                'password' => 'Make another team member an admin before you delete your account.',
            ]);

        $this->assertAuthenticatedAs($admin);
        $this->assertNotNull($admin->fresh());
        $this->assertNotNull(Contact::withoutGlobalScopes()->find($contact->id));
    }

    public function test_an_archived_or_deactivated_admin_does_not_count_as_the_other_admin(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        User::factory()->for($admin->client)->create(['role' => UserRole::Admin, 'archived_at' => now()]);
        User::factory()->for($admin->client)->create(['role' => UserRole::Admin, 'deactivated_at' => now()]);

        $this->actingAs($admin)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrors([
                'password' => 'Make another team member an admin before you delete your account.',
            ]);

        $this->assertNotNull($admin->fresh());
    }

    public function test_the_only_member_of_a_client_deletes_the_account_and_the_data(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $contact = Contact::factory()->for($user)->create();

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertNull($user->fresh());
        $this->assertNull(Contact::withoutGlobalScopes()->find($contact->id));
    }

    public function test_the_instance_owner_cannot_delete_the_account(): void
    {
        $owner = User::factory()->owner()->create(['role' => UserRole::Admin]);

        $this->actingAs($owner)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrors([
                'password' => 'The instance owner cannot delete the account.',
            ]);

        $this->assertNotNull($owner->fresh());
    }

    public function test_a_wrong_password_deletes_nothing_and_shows_only_the_password_error(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        User::factory()->for($admin->client)->create(['role' => UserRole::Editor]);

        $this->actingAs($admin)
            ->delete(route('profile.destroy'), ['password' => 'wrong-password'])
            ->assertSessionHasErrors([
                'password' => 'The password is incorrect.',
            ]);

        $this->assertNotNull($admin->fresh());
    }

    /**
     * A new table with a `user_id` foreign key that cascades on delete loses
     * team data when a member deletes the account. Each such table must be in
     * DeleteUser::AUTHORED_MODELS, or in the list of personal tables below.
     */
    public function test_every_table_that_references_users_is_handed_over_or_personal(): void
    {
        $personalTables = ['user_storage_settings'];

        $handedOver = array_map(
            static fn (string $model): string => (new $model)->getTable(),
            DeleteUser::AUTHORED_MODELS,
        );

        $referencing = collect(Schema::getTableListing(Schema::getCurrentSchemaListing(), schemaQualified: false))
            ->filter(fn (string $table): bool => collect(Schema::getForeignKeys($table))
                ->contains(fn (array $key): bool => $key['foreign_table'] === 'users' && $key['columns'] === ['user_id']))
            ->values()
            ->all();

        sort($referencing);
        $expected = array_merge($handedOver, $personalTables);
        sort($expected);

        $this->assertSame($expected, $referencing);
    }
}
