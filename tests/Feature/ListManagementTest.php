<?php

namespace Tests\Feature;

use App\Enums\ContactImportStatus;
use App\Models\ContactImport;
use App\Models\EmailIdentity;
use App\Models\MailList;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class ListManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_list_creation_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('lists.create'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('lists/Create')
        );
    }

    public function test_user_can_create_list_via_form(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('lists.store'), [
            'name' => 'VIP Customers',
        ]);

        $response->assertRedirect(route('lists.index'));

        $this->assertDatabaseHas('lists', [
            'user_id' => $user->id,
            'name' => 'VIP Customers',
        ]);
    }

    public function test_user_can_create_list_and_redirect_to_contacts_import(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('lists.store'), [
            'name' => 'Newsletter Subscribers',
            '_redirect' => 'contacts.import',
        ]);

        $list = MailList::query()->withoutGlobalScopes()->where('name', 'Newsletter Subscribers')->firstOrFail();

        $response->assertRedirect(route('contacts.import', ['list_id' => $list->id]));
        $this->assertSame($user->id, $list->user_id);
    }

    public function test_user_can_create_list_and_return_back_with_flash(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->from(route('contacts.show', 1))
            ->post(route('lists.store'), [
                'name' => 'Flash List',
                '_return_back' => true,
            ]);

        $response->assertRedirect(route('contacts.show', 1));
        $response->assertSessionHas('createdList.name', 'Flash List');
        $response->assertSessionHas('createdList.id');

        $this->assertDatabaseHas('lists', [
            'user_id' => $user->id,
            'name' => 'Flash List',
        ]);
    }

    public function test_user_can_view_list_show_page(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create(['name' => 'VIP Customers']);

        $response = $this->actingAs($user)->get(route('lists.show', $list));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('lists/Show')
            ->has('list', fn (Assert $list) => $list
                ->where('name', 'VIP Customers')
                ->etc()
            )
        );
    }

    public function test_list_show_page_displays_imports_with_status_and_progress(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();

        $pendingImport = ContactImport::factory()->for($user)->create([
            'list_id' => $list->id,
            'status' => ContactImportStatus::Pending,
            'original_filename' => 'pending-contacts.csv',
            'total_count' => 100,
            'processed_count' => 0,
            'skipped_count' => 0,
        ]);

        $processingImport = ContactImport::factory()->for($user)->processing()->create([
            'list_id' => $list->id,
            'original_filename' => 'processing-contacts.csv',
            'total_count' => 200,
            'processed_count' => 100,
            'skipped_count' => 20,
        ]);

        $completedImport = ContactImport::factory()->for($user)->completed()->create([
            'list_id' => $list->id,
            'original_filename' => 'completed-contacts.csv',
            'total_count' => 50,
            'processed_count' => 45,
            'skipped_count' => 5,
        ]);

        $response = $this->actingAs($user)->get(route('lists.show', $list));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('lists/Show')
            ->has('imports', 3)
            ->has('imports.0', fn (Assert $import) => $import
                ->where('status', ContactImportStatus::Completed->value)
                ->where('original_filename', 'completed-contacts.csv')
                ->where('progress', 100)
                ->etc()
            )
            ->has('imports.1', fn (Assert $import) => $import
                ->where('status', ContactImportStatus::Processing->value)
                ->where('original_filename', 'processing-contacts.csv')
                ->where('progress', 60)
                ->etc()
            )
            ->has('imports.2', fn (Assert $import) => $import
                ->where('status', ContactImportStatus::Pending->value)
                ->where('original_filename', 'pending-contacts.csv')
                ->where('progress', 0)
                ->etc()
            )
            ->has('importStatuses', count(ContactImportStatus::cases()))
            ->where('importStatuses.0', ['value' => 'pending', 'label' => 'Pending', 'in_progress' => true])
            ->where('importStatuses.2', ['value' => 'completed', 'label' => 'Completed', 'in_progress' => false])
        );
    }

    public function test_user_cannot_view_other_users_list(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $list = MailList::factory()->for($otherUser)->create();

        $response = $this->actingAs($user)->get(route('lists.show', $list));

        $response->assertNotFound();
    }

    public function test_list_show_page_only_displays_imports_for_that_list(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $otherList = MailList::factory()->for($user)->create();

        ContactImport::factory()->for($user)->create([
            'list_id' => $list->id,
            'original_filename' => 'this-list.csv',
        ]);

        ContactImport::factory()->for($user)->create([
            'list_id' => $otherList->id,
            'original_filename' => 'other-list.csv',
        ]);

        $response = $this->actingAs($user)->get(route('lists.show', $list));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('imports', 1)
            ->has('imports.0', fn (Assert $import) => $import
                ->where('original_filename', 'this-list.csv')
                ->etc()
            )
        );
    }

    public function test_a_duplicate_name_on_create_is_a_validation_error(): void
    {
        $user = User::factory()->create();
        MailList::factory()->for($user)->create(['name' => 'Customers']);

        $response = $this->actingAs($user)->post(route('lists.store'), ['name' => 'Customers']);

        $response->assertSessionHasErrors('name');
        $this->assertSame(1, MailList::query()->withoutGlobalScopes()->where('name', 'Customers')->count());
    }

    public function test_a_rename_to_the_name_of_another_list_is_a_validation_error(): void
    {
        $user = User::factory()->create();
        MailList::factory()->for($user)->create(['name' => 'Customers']);
        $list = MailList::factory()->for($user)->create(['name' => 'Leads']);

        $response = $this->actingAs($user)->put(route('lists.update', $list), ['name' => 'Customers']);

        $response->assertSessionHasErrors('name');
        $this->assertSame('Leads', $list->fresh()->name);
    }

    public function test_a_list_keeps_its_own_name_on_update(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create(['name' => 'Leads']);

        $this->actingAs($user)->put(route('lists.update', $list), ['name' => 'Leads'])
            ->assertSessionHasNoErrors();
    }

    public function test_the_update_saves_nothing_when_the_opt_in_message_fails(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create(['name' => 'Leads', 'opt_in_enabled' => false]);
        $identity = EmailIdentity::factory()->for($user)->create();
        Message::creating(fn () => throw new RuntimeException('The message write failed.'));

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($user)->put(route('lists.update', $list), [
                'name' => 'Customers',
                'opt_in_enabled' => true,
                'opt_in_subject' => 'Confirm your subscription',
                'opt_in_html_content' => '<p><a href="{{opt_in_url}}">Confirm</a></p>',
                'opt_in_email_identity_id' => $identity->id,
            ]);
            $this->fail('The update did not throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The message write failed.', $exception->getMessage());
        }

        $list->refresh();
        $this->assertSame('Leads', $list->name);
        $this->assertFalse($list->opt_in_enabled);
    }
}
