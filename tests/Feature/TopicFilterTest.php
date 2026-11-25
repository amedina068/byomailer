<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\MailList;
use App\Models\Topic;
use App\Models\User;
use App\Services\ContactFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TopicFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_topic_filter_any_mode_matches_subscribed_contact(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
        ]);
        $topic = Topic::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'list_id' => $list->id,
        ]);

        $subscribed = Contact::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
        ]);
        $unsubscribed = Contact::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
        ]);

        DB::table('contact_topic')->insert([
            ['contact_id' => $subscribed->id, 'topic_id' => $topic->id, 'subscribed_at' => now(), 'unsubscribed_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['contact_id' => $unsubscribed->id, 'topic_id' => $topic->id, 'subscribed_at' => now(), 'unsubscribed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $filters = [[
            'category' => 'topics',
            'mode' => 'any',
            'values' => [$topic->id],
        ]];

        $matches = app(ContactFilterService::class)
            ->applyFiltersToQuery(Contact::query(), $filters)
            ->pluck('id')
            ->all();

        $this->assertContains($subscribed->id, $matches);
        $this->assertNotContains($unsubscribed->id, $matches);
    }

    public function test_topic_filter_none_mode_excludes_subscribed(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
        ]);
        $topic = Topic::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'list_id' => $list->id,
        ]);

        $subscribed = Contact::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
        ]);
        $notSubscribed = Contact::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
        ]);

        DB::table('contact_topic')->insert([
            'contact_id' => $subscribed->id,
            'topic_id' => $topic->id,
            'subscribed_at' => now(),
            'unsubscribed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $filters = [[
            'category' => 'topics',
            'mode' => 'none',
            'values' => [$topic->id],
        ]];

        $matches = app(ContactFilterService::class)
            ->applyFiltersToQuery(Contact::query(), $filters)
            ->pluck('id')
            ->all();

        $this->assertNotContains($subscribed->id, $matches);
        $this->assertContains($notSubscribed->id, $matches);
    }

    public function test_topic_filter_all_mode_requires_all_topics_subscribed(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
        ]);
        $topicA = Topic::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id, 'list_id' => $list->id]);
        $topicB = Topic::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id, 'list_id' => $list->id]);

        $both = Contact::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id]);
        $onlyOne = Contact::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id]);

        DB::table('contact_topic')->insert([
            ['contact_id' => $both->id, 'topic_id' => $topicA->id, 'subscribed_at' => now(), 'unsubscribed_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['contact_id' => $both->id, 'topic_id' => $topicB->id, 'subscribed_at' => now(), 'unsubscribed_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['contact_id' => $onlyOne->id, 'topic_id' => $topicA->id, 'subscribed_at' => now(), 'unsubscribed_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $filters = [[
            'category' => 'topics',
            'mode' => 'all',
            'values' => [$topicA->id, $topicB->id],
        ]];

        $matches = app(ContactFilterService::class)
            ->applyFiltersToQuery(Contact::query(), $filters)
            ->pluck('id')
            ->all();

        $this->assertContains($both->id, $matches);
        $this->assertNotContains($onlyOne->id, $matches);
    }
}
