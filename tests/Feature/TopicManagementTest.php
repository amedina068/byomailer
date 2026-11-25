<?php

namespace Tests\Feature;

use App\Jobs\BackfillTopicSubscribersJob;
use App\Jobs\ProcessContactImportBatchJob;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\MailList;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TopicManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_topic_on_list_and_backfill_job_dispatches(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $list = MailList::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
        ]);

        $response = $this->actingAs($user)->post(
            route('lists.topics.store', ['list' => $list->id]),
            [
                'name' => 'Product Updates',
                'description' => 'Release notes',
            ],
        );

        $response->assertRedirect(route('lists.show', $list->id));

        $this->assertDatabaseHas('topics', [
            'list_id' => $list->id,
            'name' => 'Product Updates',
        ]);

        Bus::assertDispatched(BackfillTopicSubscribersJob::class);
    }

    public function test_backfill_job_subscribes_existing_list_contacts(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
        ]);

        $contacts = Contact::factory()->count(3)->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
        ]);

        foreach ($contacts as $contact) {
            $contact->lists()->attach($list->id);
        }

        $topic = Topic::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'list_id' => $list->id,
        ]);

        (new BackfillTopicSubscribersJob($topic->id))->handle();

        foreach ($contacts as $contact) {
            $this->assertDatabaseHas('contact_topic', [
                'contact_id' => $contact->id,
                'topic_id' => $topic->id,
                'unsubscribed_at' => null,
            ]);
        }
    }

    public function test_sync_lists_auto_subscribes_contact_to_list_topics(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
        ]);

        Topic::factory()->count(2)->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'list_id' => $list->id,
        ]);

        $contact = Contact::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
        ]);

        $contact->syncLists([$list->id]);

        $this->assertSame(2, DB::table('contact_topic')->where('contact_id', $contact->id)->count());
    }

    public function test_topic_name_must_be_unique_per_list(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
        ]);

        Topic::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'list_id' => $list->id,
            'name' => 'News',
        ]);

        $response = $this->actingAs($user)->post(
            route('lists.topics.store', ['list' => $list->id]),
            ['name' => 'News'],
        );

        $response->assertSessionHasErrors('name');
    }

    public function test_contact_import_batch_subscribes_to_existing_topics(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
        ]);
        $topic = Topic::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'list_id' => $list->id,
        ]);

        $import = ContactImport::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'list_id' => $list->id,
        ]);

        $job = new ProcessContactImportBatchJob(
            user: $user,
            mailList: $list,
            contactImport: $import,
            rows: [[
                'email' => 'imported@example.com',
                'name' => 'Imported',
                'tags' => null,
                'attributes' => [],
            ]],
        );

        app()->call([$job, 'handle']);

        $contact = Contact::withoutGlobalScopes()->where('email', 'imported@example.com')->firstOrFail();

        $this->assertDatabaseHas('contact_topic', [
            'contact_id' => $contact->id,
            'topic_id' => $topic->id,
            'unsubscribed_at' => null,
        ]);
    }

    public function test_deleting_topic_cascades_pivot_rows(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
        ]);
        $contact = Contact::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
        ]);
        $topic = Topic::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'list_id' => $list->id,
        ]);

        DB::table('contact_topic')->insert([
            'contact_id' => $contact->id,
            'topic_id' => $topic->id,
            'subscribed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $topic->delete();

        $this->assertDatabaseMissing('contact_topic', [
            'contact_id' => $contact->id,
            'topic_id' => $topic->id,
        ]);
    }

    public function test_user_can_update_a_topic(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $topic = Topic::factory()->for($user)->create(['list_id' => $list->id, 'name' => 'News']);

        $response = $this->actingAs($user)->put(
            route('lists.topics.update', ['list' => $list->id, 'topic' => $topic->id]),
            ['name' => 'Product news', 'description' => 'Release notes'],
        );

        $response->assertRedirect(route('lists.show', $list->id));
        $topic->refresh();
        $this->assertSame('Product news', $topic->name);
        $this->assertSame('Release notes', $topic->description);
    }

    public function test_a_topic_rename_to_the_name_of_another_topic_of_the_list_is_a_validation_error(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        Topic::factory()->for($user)->create(['list_id' => $list->id, 'name' => 'Events']);
        $topic = Topic::factory()->for($user)->create(['list_id' => $list->id, 'name' => 'News']);

        $this->actingAs($user)->put(
            route('lists.topics.update', ['list' => $list->id, 'topic' => $topic->id]),
            ['name' => 'Events'],
        )->assertSessionHasErrors('name');

        $this->actingAs($user)->put(
            route('lists.topics.update', ['list' => $list->id, 'topic' => $topic->id]),
            ['name' => 'News'],
        )->assertSessionHasNoErrors();
    }
}
