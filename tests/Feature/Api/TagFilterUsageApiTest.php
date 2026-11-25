<?php

namespace Tests\Feature\Api;

use App\Events\ContactTagsSynced;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\Newsletter;
use App\Models\Segment;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\Tag;
use App\Models\User;
use App\Services\ContactFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tag filters select tags by name. A rename or a delete of a tag that a
 * saved filter uses changes the audience of that filter, so the API
 * refuses it. A delete removes the tag from each contact with an event.
 */
class TagFilterUsageApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tag $tag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->tag = Tag::factory()->for($this->user)->create(['name' => 'VIP']);
        Sanctum::actingAs($this->user, ['*']);
    }

    public function test_a_rename_does_not_change_the_audience_of_a_newsletter_that_excludes_the_tag(): void
    {
        $vip = Contact::factory()->for($this->user)->create();
        $vip->tags()->attach($this->tag);
        Contact::factory()->for($this->user)->create();
        $newsletter = Newsletter::factory()->for($this->user)->withFilters($this->excludeVip())->create();

        $this->patchJson("/api/v1/tags/{$this->tag->id}", ['name' => 'Gold']);

        $this->assertSame(1, app(ContactFilterService::class)->recipientCount($this->user->client_id, $newsletter->fresh()->filters, $newsletter));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function filterHolders(): array
    {
        return [
            'draft newsletter' => ['newsletter'],
            'sequence' => ['sequence'],
            'sequence step' => ['step'],
            'segment' => ['segment'],
        ];
    }

    #[DataProvider('filterHolders')]
    public function test_a_rename_of_a_tag_in_a_saved_filter_is_refused(string $holder): void
    {
        $this->createFilterHolder($holder);

        $this->patchJson("/api/v1/tags/{$this->tag->id}", ['name' => 'Gold'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertSame('VIP', $this->tag->fresh()->name);
    }

    #[DataProvider('filterHolders')]
    public function test_a_delete_of_a_tag_in_a_saved_filter_is_refused(string $holder): void
    {
        $this->createFilterHolder($holder);

        $this->deleteJson("/api/v1/tags/{$this->tag->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tag');

        $this->assertModelExists($this->tag);
    }

    public function test_a_sent_newsletter_does_not_block_a_rename(): void
    {
        Newsletter::factory()->for($this->user)->withFilters($this->excludeVip())->create(['status' => 'sent']);

        $this->patchJson("/api/v1/tags/{$this->tag->id}", ['name' => 'Gold'])->assertOk();
    }

    /**
     * A failed newsletter does not send again, and no user can delete it.
     */
    public function test_a_failed_newsletter_does_not_block_a_rename_or_a_delete(): void
    {
        Newsletter::factory()->for($this->user)->withFilters($this->excludeVip())->create(['status' => 'failed']);

        $this->patchJson("/api/v1/tags/{$this->tag->id}", ['name' => 'Gold'])->assertOk();
        $this->deleteJson("/api/v1/tags/{$this->tag->id}")->assertNoContent();

        $this->assertModelMissing($this->tag);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function openNewsletterStatuses(): array
    {
        return [
            'scheduled' => ['scheduled'],
            'queued' => ['queued'],
            'ab_testing' => ['ab_testing'],
        ];
    }

    #[DataProvider('openNewsletterStatuses')]
    public function test_a_newsletter_that_can_still_send_blocks_a_rename(string $status): void
    {
        Newsletter::factory()->for($this->user)->withFilters($this->excludeVip())->create(['status' => $status]);

        $this->patchJson("/api/v1/tags/{$this->tag->id}", ['name' => 'Gold'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_a_delete_removes_the_tag_from_each_contact_with_an_event(): void
    {
        Event::fake([ContactTagsSynced::class]);
        Automation::factory()->for($this->user)->create([
            'condition' => ['type' => 'removed_tag', 'value' => $this->tag->id],
        ]);
        $other = Tag::factory()->for($this->user)->create(['name' => 'Lead']);
        $contacts = Contact::factory()->for($this->user)->count(2)->create();
        $contacts->each(fn (Contact $contact) => $contact->tags()->attach([$this->tag->id, $other->id]));

        $this->deleteJson("/api/v1/tags/{$this->tag->id}")->assertNoContent();

        $this->assertModelMissing($this->tag);
        Event::assertDispatchedTimes(ContactTagsSynced::class, 2);
        Event::assertDispatched(ContactTagsSynced::class, fn (ContactTagsSynced $event): bool => $event->changes['detached'] === [$this->tag->id]);
        $contacts->each(fn (Contact $contact) => $this->assertSame([$other->id], $contact->tags()->pluck('tags.id')->all()));
    }

    private function createFilterHolder(string $holder): void
    {
        $sequence = Sequence::factory()->for($this->user)->create(['status' => 'draft']);

        match ($holder) {
            'newsletter' => Newsletter::factory()->for($this->user)->withFilters($this->excludeVip())->create(),
            'sequence' => $sequence->update(['filters' => $this->excludeVip()]),
            'step' => SequenceStep::factory()->for($sequence)->create(['filters' => $this->excludeVip()]),
            'segment' => Segment::factory()->withFilters($this->excludeVip())->create(['client_id' => $this->user->client_id]),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function excludeVip(): array
    {
        return [['category' => 'tags', 'mode' => 'none', 'values' => ['VIP']]];
    }
}
