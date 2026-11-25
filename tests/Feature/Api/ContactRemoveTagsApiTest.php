<?php

namespace Tests\Feature\Api;

use App\Events\ContactTagsSynced;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\MailList;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactRemoveTagsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_removes_tags_and_dispatches_the_tag_event(): void
    {
        Event::fake([ContactTagsSynced::class]);

        [$user, $contact, $paid, $free] = $this->contactWithTags();
        $this->removedTagAutomation($user, $paid);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/v1/contacts/{$contact->id}", ['remove_tags' => ['PaidUser']])
            ->assertOk()
            ->assertJsonPath('data.tags.0.name', 'FreeUser')
            ->assertJsonCount(1, 'data.tags');

        $this->assertSame([$free->id], $contact->tags()->pluck('tags.id')->all());
        $this->assertArrayNotHasKey('remove_tags', $contact->refresh()->extra_attributes ?? []);

        Event::assertDispatchedTimes(ContactTagsSynced::class, 1);
        Event::assertDispatched(ContactTagsSynced::class, fn (ContactTagsSynced $event): bool => $event->contact->is($contact)
            && array_values($event->changes['detached']) === [$paid->id]
            && ($event->changes['attached'] ?? []) === []);
    }

    public function test_removed_tag_automation_runs_after_an_api_removal(): void
    {
        [$user, $contact, $paid] = $this->contactWithTags();
        $churned = Tag::factory()->for($user)->create(['name' => 'Churned']);
        $this->removedTagAutomation($user, $paid, $churned);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/v1/contacts/{$contact->id}", ['remove_tags' => ['PaidUser']])->assertOk();

        $this->assertEqualsCanonicalizing(
            ['FreeUser', 'Churned'],
            $contact->tags()->pluck('name')->all(),
        );
    }

    public function test_store_adds_and_removes_tags_in_one_sync(): void
    {
        Event::fake([ContactTagsSynced::class]);

        [$user, $contact, $paid, $free] = $this->contactWithTags();
        $this->removedTagAutomation($user, $paid);
        $list = MailList::factory()->for($user)->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/v1/lists/{$list->id}/contacts", [
            'email' => $contact->email,
            'tags' => ['subscription_churned'],
            'remove_tags' => ['PaidUser', 'user_reccurring_subscription'],
        ])->assertOk();

        $churned = Tag::query()->where('name', 'subscription_churned')->firstOrFail();

        $this->assertEqualsCanonicalizing([$free->id, $churned->id], $contact->tags()->pluck('tags.id')->all());

        Event::assertDispatchedTimes(ContactTagsSynced::class, 1);
        Event::assertDispatched(ContactTagsSynced::class, fn (ContactTagsSynced $event): bool => array_values($event->changes['detached']) === [$paid->id]
            && array_values($event->changes['attached']) === [$churned->id]);
    }

    public function test_tag_in_tags_and_remove_tags_returns_422_and_changes_nothing(): void
    {
        [$user, $contact, $paid, $free] = $this->contactWithTags();
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/v1/contacts/{$contact->id}", [
            'tags' => ['VIP', ' paiduser '],
            'remove_tags' => ['PaidUser'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['remove_tags' => 'These tags are in tags and in remove_tags: paiduser.']);

        $this->assertEqualsCanonicalizing([$paid->id, $free->id], $contact->tags()->pluck('tags.id')->all());
        $this->assertNull(Tag::query()->where('name', 'VIP')->first());
    }

    public function test_removing_an_absent_or_unknown_tag_changes_nothing(): void
    {
        Event::fake([ContactTagsSynced::class]);

        [$user, $contact, $paid, $free] = $this->contactWithTags();
        $other = Tag::factory()->for($user)->create(['name' => 'Other']);
        $this->removedTagAutomation($user, $other);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/v1/contacts/{$contact->id}", ['remove_tags' => ['Other', 'NoSuchTag']])
            ->assertOk()
            ->assertJsonCount(2, 'data.tags');

        $this->assertEqualsCanonicalizing([$paid->id, $free->id], $contact->tags()->pluck('tags.id')->all());
        $this->assertNull(Tag::query()->where('name', 'NoSuchTag')->first());
        Event::assertNotDispatched(ContactTagsSynced::class);
    }

    public function test_tags_without_remove_tags_still_only_add(): void
    {
        Event::fake([ContactTagsSynced::class]);

        [$user, $contact, $paid, $free] = $this->contactWithTags();
        $this->removedTagAutomation($user, $paid);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/v1/contacts/{$contact->id}", ['tags' => ['VIP']])
            ->assertOk()
            ->assertJsonCount(3, 'data.tags');

        $vip = Tag::query()->where('name', 'VIP')->firstOrFail();

        $this->assertEqualsCanonicalizing([$paid->id, $free->id, $vip->id], $contact->tags()->pluck('tags.id')->all());
        Event::assertDispatched(ContactTagsSynced::class, fn (ContactTagsSynced $event): bool => ($event->changes['detached'] ?? []) === []
            && array_values($event->changes['attached']) === [$vip->id]);
    }

    /**
     * @return array{0: User, 1: Contact, 2: Tag, 3: Tag}
     */
    private function contactWithTags(): array
    {
        $user = User::factory()->create();
        $paid = Tag::factory()->for($user)->create(['name' => 'PaidUser']);
        $free = Tag::factory()->for($user)->create(['name' => 'FreeUser']);
        $contact = Contact::factory()->for($user)->create(['extra_attributes' => []]);
        $contact->tags()->attach([$paid->id, $free->id]);

        return [$user, $contact, $paid, $free];
    }

    /**
     * A live removed_tag automation makes syncTags() dispatch ContactTagsSynced.
     */
    private function removedTagAutomation(User $user, Tag $condition, ?Tag $action = null): Automation
    {
        return Automation::factory()->for($user)->create([
            'condition' => ['type' => 'removed_tag', 'value' => $condition->id],
            'actions' => [['type' => 'add_tag', 'value' => ($action ?? $condition)->id]],
        ]);
    }
}
