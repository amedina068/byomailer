<?php

namespace Tests\Feature;

use App\Events\ContactAttributesChanged;
use App\Events\ContactResubscribed;
use App\Events\ContactTagsSynced;
use App\Jobs\ProcessContactImportBatchJob;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\MailList;
use App\Models\Tag;
use App\Models\User;
use App\Services\ContactMembership;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ContactMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_partial_put_and_list_upsert_preserve_omitted_attributes(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create(['extra_attributes' => ['url' => 'https://example.com', 'plan' => 'free']]);
        Sanctum::actingAs($user, ['*']);

        $this->putJson("/api/v1/contacts/{$contact->id}", ['plan' => 'pro'])->assertOk()
            ->assertJsonPath('data.extra_attributes.url', 'https://example.com');
        $this->postJson("/api/v1/lists/{$list->id}/contacts", ['email' => $contact->email, 'event' => 'purchase'])->assertOk()
            ->assertJsonPath('data.extra_attributes.url', 'https://example.com')
            ->assertJsonPath('data.extra_attributes.plan', 'pro');
        $this->putJson("/api/v1/contacts/{$contact->id}", ['tags' => ['buyer']])->assertOk();
        $this->assertSame(['url' => 'https://example.com', 'plan' => 'pro', 'event' => 'purchase'], $contact->fresh()->extra_attributes);
    }

    public function test_list_upsert_preserves_attributes_and_accepts_explicit_null(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create(['extra_attributes' => ['url' => 'https://example.com', 'plan' => 'free']]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/v1/lists/{$list->id}/contacts", ['email' => $contact->email, 'plan' => null])->assertOk()
            ->assertJsonPath('data.extra_attributes.url', 'https://example.com');

        $this->assertSame(['url' => 'https://example.com', 'plan' => null], $contact->fresh()->extra_attributes);
    }

    public function test_stale_instances_patch_the_fresh_row_and_return_existing_status(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['extra_attributes' => ['url' => 'kept']]);
        $this->actingAsTenant($user->client_id);
        $first = $contact->fresh();
        $second = $contact->fresh();
        $membership = app(ContactMembership::class);

        $membership->persist($user, $first, ['extra_attributes' => ['plan' => 'pro']]);
        $result = $membership->persist($user, $second, ['extra_attributes' => ['event' => 'purchase']]);

        $this->assertNotSame($second, $result);
        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertSame(['url' => 'kept', 'plan' => 'pro', 'event' => 'purchase'], $result->extra_attributes);
        $this->assertSame($result->extra_attributes, $contact->fresh()->extra_attributes);
    }

    public function test_shallow_patch_preserves_exact_values_and_normalized_event_changes(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['extra_attributes' => [
            'url' => 'kept', 'off' => 'old', 'blank' => 'old', 'nullable' => 'old', 'nested' => ['old' => 1],
            'kept_false' => false, 'kept_blank' => '', 'kept_null' => null, 'kept_nested' => ['a' => ['b']],
        ]]);
        $this->actingAsTenant($user->client_id);
        $this->liveTriggers($user);
        $received = [];
        Event::listen(ContactAttributesChanged::class, function (ContactAttributesChanged $event) use (&$received): void {
            $received[] = $event->changes;
        });
        $membership = app(ContactMembership::class);
        $result = $membership->persist($user, $contact, ['extra_attributes' => [
            'off' => false, 'blank' => '', 'nullable' => null, 'nested' => ['new' => 2],
        ]]);

        $expected = ['url' => 'kept', 'off' => false, 'blank' => '', 'nullable' => null, 'nested' => ['new' => 2],
            'kept_false' => false, 'kept_blank' => '', 'kept_null' => null, 'kept_nested' => ['a' => ['b']]];
        $this->assertSameJsonData($expected, $result->fresh()->extra_attributes);
        $this->assertSameJsonData([[
            'off' => ['old' => 'old', 'new' => null], 'blank' => ['old' => 'old', 'new' => null],
            'nullable' => ['old' => 'old', 'new' => null], 'nested' => ['old' => '{"old":1}', 'new' => '{"new":2}'],
        ]], $received);
        $membership->persist($user, $result, ['extra_attributes' => []]);
        $membership->persist($user, $result, ['extra_attributes' => ['off' => '', 'blank' => null, 'nullable' => false]]);
        $this->assertCount(1, $received);
        $this->assertSameJsonData(array_replace($expected, ['off' => '', 'blank' => null, 'nullable' => false]), $result->fresh()->extra_attributes);
    }

    public function test_persist_without_auth_rejects_other_clients_and_preserves_their_tags(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $contact = Contact::factory()->for($other)->create(['extra_attributes' => ['url' => 'kept']]);
        $tag = Tag::factory()->for($other)->create();
        $contact->tags()->attach($tag);
        $this->assertFalse(auth()->check());

        try {
            Tenant::run($user->client_id, fn () => app(ContactMembership::class)->persist($user, $contact, ['extra_attributes' => ['plan' => 'pro'], 'tags' => [$tag->name]]));
            $this->fail('Cross-client persist must fail.');
        } catch (ModelNotFoundException) {
            $this->assertSame(['url' => 'kept'], $contact->fresh()->extra_attributes);
            $this->assertSame([$tag->id], Tenant::run($other->client_id, fn (): array => $contact->tags()->pluck('tags.id')->all()));
        }
    }

    public function test_persist_without_auth_allows_a_same_client_teammate(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->create(['client_id' => $owner->client_id]);
        $contact = Contact::factory()->for($owner)->create(['extra_attributes' => ['url' => 'kept']]);

        $result = Tenant::run($teammate->client_id, fn (): Contact => app(ContactMembership::class)->persist($teammate, $contact, ['extra_attributes' => ['plan' => 'pro']]));

        $this->assertSame($owner->id, $result->user_id);
        $this->assertSame(['url' => 'kept', 'plan' => 'pro'], $result->fresh()->extra_attributes);
    }

    public function test_resubscribe_with_a_stale_model_keeps_an_opt_out_of_the_contact(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['unsubscribed_at' => now()->subDay(), 'unsubscribed_by_contact' => false]);
        $this->actingAsTenant($user->client_id);
        $stale = $contact->fresh();
        $contact->forceFill(['unsubscribed_by_contact' => true])->save();

        $this->assertFalse(app(ContactMembership::class)->resubscribe($user, $stale));

        $this->assertNotNull($contact->fresh()->unsubscribed_at);
        $this->assertTrue($stale->unsubscribed_by_contact);
    }

    public function test_resubscribe_clears_an_unsubscribe_that_an_admin_made(): void
    {
        Event::fake([ContactResubscribed::class]);
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['unsubscribed_at' => now()->subDay(), 'unsubscribed_by_contact' => false]);
        $this->actingAsTenant($user->client_id);

        $this->assertTrue(app(ContactMembership::class)->resubscribe($user, $contact));

        $this->assertNull($contact->unsubscribed_at);
        $this->assertNull($contact->fresh()->unsubscribed_at);
        Event::assertDispatchedTimes(ContactResubscribed::class, 1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function implicitTagWrites(): array
    {
        return ['attach tags' => ['attach'], 'change tags' => ['change'], 'import' => ['import']];
    }

    /**
     * Another request creates the tag right after the first select on tags.
     * The implicit create must reuse that tag and not fail on the unique index.
     */
    #[DataProvider('implicitTagWrites')]
    public function test_an_implicit_tag_create_reuses_a_tag_that_a_parallel_request_created(string $write): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['email' => 'ada@example.com']);
        $this->actingAsTenant($user->client_id);
        $done = false;
        DB::listen(function (QueryExecuted $query) use ($user, &$done): void {
            if ($done || ! str_starts_with($query->sql, 'select * from "tags"') || ! in_array('VIP', $query->bindings, true)) {
                return;
            }

            $done = true;

            if (! Tag::withoutGlobalScopes()->where('client_id', $user->client_id)->where('name', 'VIP')->exists()) {
                Tag::factory()->for($user)->create(['name' => 'VIP']);
            }
        });

        match ($write) {
            'attach' => app(ContactMembership::class)->attachTags($user, $contact, ['VIP']),
            'change' => app(ContactMembership::class)->changeTags($user, $contact, ['VIP'], []),
            'import' => $this->importRow($user, ['email' => 'ada@example.com', 'name' => null, 'tags' => 'VIP', 'attributes' => []]),
        };

        $tag = Tag::query()->where('name', 'VIP')->sole();
        $this->assertSame([$tag->id], $contact->tags()->pluck('tags.id')->all());
    }

    /**
     * @param  array{email: string, name: ?string, tags: ?string, attributes: array<string, string>}  $row
     */
    private function importRow(User $user, array $row): void
    {
        $list = MailList::factory()->for($user)->create();
        $contactImport = ContactImport::factory()->processing()->for($user)->for($list, 'mailList')
            ->create(['total_count' => 1, 'processed_count' => 0, 'skipped_count' => 0]);

        [$job] = (new ProcessContactImportBatchJob($user, $list, $contactImport, [$row]))->withFakeBatch();
        app()->call([$job, 'handle']);
    }

    public function test_new_contact_returns_original_instance_and_created_flag(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $contact = new Contact(['email' => 'new@example.com']);

        $result = app(ContactMembership::class)->persist($user, $contact, ['extra_attributes' => ['plan' => 'pro']]);

        $this->assertSame($contact, $result);
        $this->assertTrue($result->wasRecentlyCreated);
        $this->assertSame(['plan' => 'pro'], $result->fresh()->extra_attributes);
    }

    public function test_persist_events_wait_for_outer_commit_and_observe_committed_state(): void
    {
        [$user, $contact, $tag] = $this->eventContact();
        $this->actingAsTenant($user->client_id);
        $received = $this->listenForPersistEvents();
        DB::transaction(function () use ($user, $contact, &$received): void {
            app(ContactMembership::class)->persist($user, $contact, ['extra_attributes' => ['plan' => 'pro'], 'tags' => ['buyer']]);
            $this->assertSame([], $received->events);
        });

        $this->assertSame([ContactAttributesChanged::class, ContactTagsSynced::class], array_column($received->events, 'type'));
        foreach ($received->events as $event) {
            $this->assertSame(['url' => 'kept', 'plan' => 'pro'], $event['attributes']);
            $this->assertSame([$tag->id], $event['tags']);
        }
        $this->assertSame(['plan' => ['old' => 'free', 'new' => 'pro']], $received->events[0]['changes']);
        $this->assertSame([$tag->id], $received->events[1]['changes']['attached']);
    }

    public function test_persist_rollback_restores_attributes_and_tags_without_events(): void
    {
        [$user, $contact] = $this->eventContact();
        $this->actingAsTenant($user->client_id);
        $received = $this->listenForPersistEvents();
        try {
            DB::transaction(function () use ($user, $contact, $received): void {
                app(ContactMembership::class)->persist($user, $contact, ['extra_attributes' => ['plan' => 'pro'], 'tags' => ['buyer']]);
                $this->assertSame([], $received->events);
                throw new RuntimeException('rollback');
            });
            $this->fail('Transaction must throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }
        $this->assertSame(['url' => 'kept', 'plan' => 'free'], $contact->fresh()->extra_attributes);
        $this->assertSame([], $contact->tags()->pluck('tags.id')->all());
        $this->assertSame([], $received->events);
    }

    private function liveTriggers(User $user, ?Tag $tag = null): void
    {
        Automation::factory()->for($user)->create(['condition' => ['type' => 'attribute_changed', 'attribute' => 'plan', 'operator' => 'changed'], 'actions' => []]);
        if ($tag) {
            Automation::factory()->for($user)->create(['condition' => ['type' => 'tag_added', 'value' => $tag->id], 'actions' => []]);
        }
    }

    /** @return array{User, Contact, Tag} */
    private function eventContact(): array
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['extra_attributes' => ['url' => 'kept', 'plan' => 'free']]);
        $tag = Tag::factory()->for($user)->create(['name' => 'buyer']);
        $this->liveTriggers($user, $tag);

        return [$user, $contact, $tag];
    }

    private function listenForPersistEvents(): object
    {
        $received = (object) ['events' => []];
        foreach ([ContactAttributesChanged::class, ContactTagsSynced::class] as $type) {
            Event::listen($type, function (ContactAttributesChanged|ContactTagsSynced $event) use ($received): void {
                $received->events[] = ['type' => $event::class, 'changes' => $event->changes,
                    'attributes' => $event->contact->fresh()->extra_attributes,
                    'tags' => $event->contact->tags()->pluck('tags.id')->all()];
            });
        }

        return $received;
    }
}
