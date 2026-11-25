<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\TestMail;
use App\Models\Topic;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PreferenceCenterTest extends TestCase
{
    use RefreshDatabase;

    private function createContactMail(User $user, Contact $contact): ContactMail
    {
        $newsletter = Newsletter::factory()->for($user)->create();

        return ContactMail::factory()->for($contact)->create([
            'mailable_type' => Newsletter::class,
            'mailable_id' => $newsletter->id,
        ]);
    }

    public function test_signed_show_route_returns_topics_for_contact(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id]);
        Topic::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id, 'list_id' => $list->id]);

        $contact = Contact::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id]);
        Tenant::run($user->client_id, fn () => $contact->syncLists([$list->id]));
        $contactMail = $this->createContactMail($user, $contact);

        $url = URL::signedRoute('preferences.show', ['contactMail' => $contactMail->uuid]);

        $this->get($url)->assertOk();
    }

    public function test_show_route_tells_the_page_that_the_contact_unsubscribed_from_all_emails(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id]);
        Topic::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id, 'list_id' => $list->id]);

        $contact = Contact::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'unsubscribed_at' => now(),
            'unsubscribed_by_contact' => true,
        ]);
        Tenant::run($user->client_id, fn () => $contact->syncLists([$list->id]));
        $contactMail = $this->createContactMail($user, $contact);

        $url = URL::signedRoute('preferences.show', ['contactMail' => $contactMail->uuid]);

        $this->get($url)->assertInertia(fn (Assert $page) => $page
            ->component('preferences/Show')
            ->where('unsubscribedFromAll', true));
    }

    public function test_show_route_tells_the_page_that_a_subscribed_contact_gets_emails(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id]);
        $contactMail = $this->createContactMail($user, $contact);

        $url = URL::signedRoute('preferences.show', ['contactMail' => $contactMail->uuid]);

        $this->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('unsubscribedFromAll', false));
    }

    public function test_unsigned_show_route_is_rejected(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id]);
        $contactMail = $this->createContactMail($user, $contact);

        $this->get(route('preferences.show', ['contactMail' => $contactMail->uuid]))
            ->assertForbidden();
    }

    public function test_update_ignores_topics_not_belonging_to_contact_lists(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id]);
        $topic = Topic::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id, 'list_id' => $list->id]);

        $otherUser = User::factory()->create();
        $otherList = MailList::factory()->create(['user_id' => $otherUser->id, 'client_id' => $otherUser->client_id]);
        $foreignTopic = Topic::factory()->create(['user_id' => $otherUser->id, 'client_id' => $otherUser->client_id, 'list_id' => $otherList->id]);

        $contact = Contact::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id]);
        $contact->lists()->attach($list->id);
        $contactMail = $this->createContactMail($user, $contact);

        $this->patch(route('preferences.update', ['contactMail' => $contactMail->uuid]), [
            'subscribed_topic_ids' => [$topic->id, $foreignTopic->id],
        ])->assertRedirect();

        $this->assertDatabaseHas('contact_topic', [
            'contact_id' => $contact->id,
            'topic_id' => $topic->id,
            'unsubscribed_at' => null,
        ]);
        $this->assertDatabaseMissing('contact_topic', [
            'contact_id' => $contact->id,
            'topic_id' => $foreignTopic->id,
        ]);
    }

    public function test_destroy_globally_unsubscribes_contact_and_all_topics(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id]);
        $topic = Topic::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id, 'list_id' => $list->id]);

        $contact = Contact::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id]);
        $contact->lists()->attach($list->id);
        $contact->subscribeToListTopics([$list->id]);
        $contactMail = $this->createContactMail($user, $contact);

        $this->delete(route('preferences.destroy', ['contactMail' => $contactMail->uuid]))
            ->assertRedirect();

        $contact->refresh();
        $this->assertNotNull($contact->unsubscribed_at);
        $this->assertTrue((bool) $contact->unsubscribed_by_contact);
        $this->assertNull(
            DB::table('contact_topic')
                ->where('contact_id', $contact->id)
                ->where('topic_id', $topic->id)
                ->whereNull('unsubscribed_at')
                ->first(),
        );
    }

    public function test_update_with_any_topic_on_implicit_resubscribes_global_unsub(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id]);
        $topic = Topic::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id, 'list_id' => $list->id]);

        $contact = Contact::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'unsubscribed_at' => now(),
            'unsubscribed_by_contact' => true,
        ]);
        $contact->lists()->attach($list->id);
        $contactMail = $this->createContactMail($user, $contact);

        $this->patch(route('preferences.update', ['contactMail' => $contactMail->uuid]), [
            'subscribed_topic_ids' => [$topic->id],
        ])->assertRedirect();

        $contact->refresh();
        $this->assertNull($contact->unsubscribed_at);
        $this->assertFalse((bool) $contact->unsubscribed_by_contact);
    }

    public function test_update_with_no_topics_keeps_global_unsub(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id]);
        Topic::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id, 'list_id' => $list->id]);

        $contact = Contact::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'unsubscribed_at' => now(),
            'unsubscribed_by_contact' => true,
        ]);
        $contact->lists()->attach($list->id);
        $contactMail = $this->createContactMail($user, $contact);

        $this->patch(route('preferences.update', ['contactMail' => $contactMail->uuid]), [
            'subscribed_topic_ids' => [],
        ])->assertRedirect();

        $contact->refresh();
        $this->assertNotNull($contact->unsubscribed_at);
    }

    public function test_resubscribe_clears_global_unsub_and_topic_unsubs(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id]);
        $topic = Topic::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id, 'list_id' => $list->id]);

        $contact = Contact::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'unsubscribed_at' => now(),
            'unsubscribed_by_contact' => true,
        ]);
        $contact->lists()->attach($list->id);
        DB::table('contact_topic')->insert([
            'contact_id' => $contact->id,
            'topic_id' => $topic->id,
            'subscribed_at' => now()->subDay(),
            'unsubscribed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $contactMail = $this->createContactMail($user, $contact);

        $this->post(route('preferences.resubscribe', ['contactMail' => $contactMail->uuid]))
            ->assertRedirect();

        $contact->refresh();
        $this->assertNull($contact->unsubscribed_at);
        $this->assertFalse((bool) $contact->unsubscribed_by_contact);
        $this->assertNotNull(
            DB::table('contact_topic')
                ->where('contact_id', $contact->id)
                ->where('topic_id', $topic->id)
                ->whereNull('unsubscribed_at')
                ->first(),
        );
    }

    public function test_update_unsubscribes_topics_not_in_selection(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id]);
        $topicA = Topic::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id, 'list_id' => $list->id]);
        $topicB = Topic::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id, 'list_id' => $list->id]);

        $contact = Contact::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id]);
        $contact->lists()->attach($list->id);
        $contact->subscribeToListTopics([$list->id]);
        $contactMail = $this->createContactMail($user, $contact);

        $this->patch(route('preferences.update', ['contactMail' => $contactMail->uuid]), [
            'subscribed_topic_ids' => [$topicA->id],
        ])->assertRedirect();

        $this->assertDatabaseHas('contact_topic', [
            'contact_id' => $contact->id,
            'topic_id' => $topicA->id,
            'unsubscribed_at' => null,
        ]);
        $row = DB::table('contact_topic')
            ->where('contact_id', $contact->id)
            ->where('topic_id', $topicB->id)
            ->first();
        $this->assertNotNull($row->unsubscribed_at);
    }

    public function test_test_mail_shows_preferences_with_banner(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $contact = Contact::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id]);

        $testMail = TestMail::create([
            'client_id' => $user->client_id,
            'user_id' => $user->id,
            'mailable_type' => Newsletter::class,
            'mailable_id' => $newsletter->id,
            'sent_at' => now(),
        ]);

        $contactMail = ContactMail::factory()->for($contact)->create([
            'mailable_type' => TestMail::class,
            'mailable_id' => $testMail->id,
        ]);

        $url = URL::signedRoute('preferences.show', ['contactMail' => $contactMail->uuid]);

        $this->get($url)->assertOk()->assertInertia(
            fn ($page) => $page
                ->component('preferences/Show')
                ->where('isTestMail', true),
        );
    }
}
