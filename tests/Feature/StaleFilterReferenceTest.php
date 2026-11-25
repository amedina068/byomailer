<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\MailList;
use App\Models\Tag;
use App\Models\Topic;
use App\Models\User;
use App\Services\ContactFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A stored filter can name a tag, list or topic that was deleted or renamed
 * after the save. Such a filter must never make the audience larger.
 */
class StaleFilterReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_none_filter_on_a_deleted_tag_matches_no_contact(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $tag = Tag::factory()->for($user)->create(['name' => 'do-not-mail']);
        Contact::factory()->for($user)->create()->tags()->attach($tag);
        Contact::factory()->for($user)->create();
        $filters = [['connector' => 'and', 'category' => 'tags', 'mode' => 'none', 'values' => ['do-not-mail']]];

        $this->assertSame(1, $this->recipientCount($user, $filters));

        $tag->delete();

        $this->assertSame(0, $this->recipientCount($user, $filters));
    }

    public function test_a_none_filter_on_a_renamed_tag_matches_no_contact(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $tag = Tag::factory()->for($user)->create(['name' => 'do-not-mail']);
        Contact::factory()->for($user)->create()->tags()->attach($tag);
        Contact::factory()->for($user)->create();
        $filters = [['connector' => 'and', 'category' => 'tags', 'mode' => 'none', 'values' => ['do-not-mail']]];

        $tag->update(['name' => 'blocked']);

        $this->assertSame(0, $this->recipientCount($user, $filters));
    }

    public function test_a_none_filter_on_a_tag_of_another_client_matches_no_contact(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        Tag::factory()->create(['name' => 'do-not-mail']);
        Contact::factory()->for($user)->create();
        $filters = [['connector' => 'and', 'category' => 'tags', 'mode' => 'none', 'values' => ['do-not-mail']]];

        $this->assertSame(0, $this->recipientCount($user, $filters));
    }

    public function test_a_none_filter_on_a_deleted_list_matches_no_contact(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();
        Contact::factory()->for($user)->create()->lists()->attach($list);
        Contact::factory()->for($user)->create();
        $filters = [['connector' => 'and', 'category' => 'lists', 'mode' => 'none', 'values' => [$list->id]]];

        $this->assertSame(1, $this->recipientCount($user, $filters));

        $list->delete();

        $this->assertSame(0, $this->recipientCount($user, $filters));
    }

    public function test_a_none_filter_on_a_deleted_topic_matches_no_contact(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $topic = Topic::factory()->for($user)->create();
        Contact::factory()->for($user)->create()->topics()->attach($topic, ['subscribed_at' => now()]);
        Contact::factory()->for($user)->create();
        $filters = [['connector' => 'and', 'category' => 'topics', 'mode' => 'none', 'values' => [$topic->id]]];

        $this->assertSame(1, $this->recipientCount($user, $filters));

        $topic->delete();

        $this->assertSame(0, $this->recipientCount($user, $filters));
    }

    public function test_a_sequence_step_check_refuses_a_contact_when_the_none_tag_is_gone(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $tag = Tag::factory()->for($user)->create(['name' => 'do-not-mail']);
        $contact = Contact::factory()->for($user)->create();
        $filters = [['connector' => 'and', 'category' => 'tags', 'mode' => 'none', 'values' => ['do-not-mail']]];
        $service = app(ContactFilterService::class);

        $this->assertTrue($service->contactMatchesFilters($contact, $filters));

        $tag->delete();

        $this->assertFalse($service->contactMatchesFilters($contact, $filters));
    }

    /**
     * @param  array<int, array<string, mixed>>  $filters
     */
    private function recipientCount(User $user, array $filters): int
    {
        return app(ContactFilterService::class)->recipientCount($user->client_id, $filters);
    }
}
