<?php

namespace Tests\Feature;

use App\Exceptions\MissingTenantException;
use App\Models\Client;
use App\Models\Contact;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\User;
use App\Scopes\ClientScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClientIntroductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_factory_creates_a_client(): void
    {
        $user = User::factory()->create();

        $this->assertNotNull($user->client_id);
        $this->assertDatabaseHas('clients', ['id' => $user->client_id]);
    }

    public function test_user_belongs_to_client(): void
    {
        $user = User::factory()->create();

        $this->assertInstanceOf(Client::class, $user->client);
        $this->assertSame($user->client_id, $user->client->id);
    }

    public function test_client_has_many_users(): void
    {
        $client = Client::factory()->create();
        $users = User::factory()->count(2)->create(['client_id' => $client->id]);

        $this->assertCount(2, $client->users);
        $this->assertTrue($client->users->contains($users->first()));
    }

    public function test_resource_factory_assigns_client_id_from_user(): void
    {
        $user = User::factory()->create();

        $contact = Contact::factory()->create(['user_id' => $user->id]);
        $list = MailList::factory()->create(['user_id' => $user->id]);

        $this->assertSame($user->client_id, $contact->client_id);
        $this->assertSame($user->client_id, $list->client_id);
    }

    public function test_client_scope_filters_contacts_by_authenticated_user_client(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $contactA = Contact::factory()->create(['user_id' => $userA->id]);
        Contact::factory()->create(['user_id' => $userB->id]);

        $this->actingAs($userA);

        $contacts = Contact::all();

        $this->assertCount(1, $contacts);
        $this->assertSame($contactA->id, $contacts->first()->id);
    }

    public function test_client_scope_allows_all_users_in_same_client_to_see_shared_data(): void
    {
        $client = Client::factory()->create();
        $userA = User::factory()->create(['client_id' => $client->id]);
        $userB = User::factory()->create(['client_id' => $client->id]);

        Contact::factory()->create(['user_id' => $userA->id, 'client_id' => $client->id]);
        Contact::factory()->create(['user_id' => $userB->id, 'client_id' => $client->id]);

        $this->actingAs($userA);

        $this->assertCount(2, Contact::all());
    }

    public function test_client_scope_refuses_a_query_with_no_tenant(): void
    {
        $user = User::factory()->create();
        Contact::factory()->count(3)->create(['user_id' => $user->id]);

        $this->expectException(MissingTenantException::class);
        $this->expectExceptionMessage('The [App\Models\Contact] query has no tenant. Run the code inside Tenant::run() or use withoutGlobalScopes() with an explicit client_id.');

        Contact::all();
    }

    public function test_client_scope_is_registered_on_resource_models(): void
    {
        $user = User::factory()->create();

        $contact = Contact::factory()->create(['user_id' => $user->id]);

        $this->assertArrayHasKey(ClientScope::class, $contact->getGlobalScopes());
    }

    public function test_without_global_scopes_bypasses_client_scope(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        Contact::factory()->create(['user_id' => $userA->id]);
        Contact::factory()->create(['user_id' => $userB->id]);

        $this->actingAs($userA);

        $this->assertCount(1, Contact::all());
        $this->assertCount(2, Contact::withoutGlobalScopes()->get());
    }

    public function test_billing_tables_and_columns_are_gone(): void
    {
        $this->assertFalse(Schema::hasTable('subscriptions'));
        $this->assertFalse(Schema::hasTable('subscription_items'));
        $this->assertFalse(Schema::hasColumn('clients', 'stripe_id'));
        $this->assertFalse(Schema::hasColumn('clients', 'trial_ends_at'));
    }

    public function test_newsletter_factory_assigns_client_id_from_user(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->create(['user_id' => $user->id]);

        $this->assertSame($user->client_id, $newsletter->client_id);
    }
}
