<?php

namespace Tests\Feature;

use App\Jobs\SendNewsletterChunkJob;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\Tag;
use App\Models\User;
use App\Services\ContactFilterService;
use App\Services\NewsletterTransport;
use App\Services\SendNewsletterService;
use Illuminate\Bus\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class NewsletterRecipientsQueryTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    private function setupTransportMock(int $chunkSize = 50): void
    {
        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('usesSesApi')->andReturn(true);
        $transport->shouldReceive('createTemplate')->andReturn('tpl-test');
        $transport->shouldReceive('getChunkSize')->andReturn($chunkSize);
        $this->app->instance(NewsletterTransport::class, $transport);
    }

    private function fakeBatch(): Batch
    {
        $batch = Mockery::mock(Batch::class);
        $batch->shouldReceive('add')->andReturnSelf();

        return $batch;
    }

    private function createQueuedNewsletter(User $user, array $filters = []): Newsletter
    {
        if (! $user->sesCredential()->exists()) {
            EmailCredential::factory()->for($user->client)->for($user)->withTenant()->create();
        }
        $identity = EmailIdentity::factory()->for($user)->create();

        $newsletter = Newsletter::factory()->for($user)->create([
            'status' => 'queued',
            'filters' => $filters,
        ]);

        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Test Sender',
        ]);

        return $newsletter;
    }

    private function getPreviewCount(User $user, array $filters): int
    {
        $response = $this->actingAs($user)->postJson(route('newsletters.contacts.preview'), [
            'filters' => $filters,
        ]);

        $response->assertOk();

        return $response->json('count');
    }

    private function getSendCount(Newsletter $newsletter, Batch $batch): int
    {
        app(SendNewsletterService::class)->send($newsletter, $batch);

        return $newsletter->fresh()->total_contacts_reached;
    }

    private function createQueuedAbNewsletter(User $user, array $filters = []): Newsletter
    {
        if (! $user->sesCredential()->exists()) {
            EmailCredential::factory()->for($user->client)->for($user)->withTenant()->create();
        }
        $identity = EmailIdentity::factory()->for($user)->create();

        $newsletter = Newsletter::factory()->for($user)->withAbTest()->create([
            'status' => 'queued',
            'filters' => $filters,
        ]);

        $newsletter->messageA()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Test Sender',
        ]);

        $newsletter->messageB()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Test Sender',
        ]);

        return $newsletter;
    }

    /**
     * @return array{ids: int[], chunks: int}
     */
    private function captureDispatchedIds(Newsletter $newsletter, int $chunkSize = 50): array
    {
        $this->setupTransportMock($chunkSize);

        $dispatchedIds = [];
        $chunkCount = 0;

        $batch = Mockery::mock(Batch::class);
        $batch->shouldReceive('add')->andReturnUsing(function (array $jobs) use (&$dispatchedIds, &$chunkCount, $batch) {
            foreach ($jobs as $job) {
                $dispatchedIds = array_merge($dispatchedIds, $job->contactIds);
                $chunkCount++;
            }

            return $batch;
        });

        app(SendNewsletterService::class)->send($newsletter, $batch);

        sort($dispatchedIds);

        return ['ids' => $dispatchedIds, 'chunks' => $chunkCount];
    }

    /**
     * @return array{all_ids: int[], group_a_ids: int[], group_b_ids: int[], jobs: SendNewsletterChunkJob[]}
     */
    private function captureAbDispatchedIds(Newsletter $newsletter, int $chunkSize = 50): array
    {
        $this->setupTransportMock($chunkSize);

        $jobs = [];

        $batch = Mockery::mock(Batch::class);
        $batch->shouldReceive('add')->andReturnUsing(function (array $added) use (&$jobs, $batch) {
            $jobs = array_merge($jobs, $added);

            return $batch;
        });

        app(SendNewsletterService::class)->send($newsletter, $batch);

        $messageAId = $newsletter->messageA->id;

        $groupAIds = [];
        $groupBIds = [];

        foreach ($jobs as $job) {
            if ($job->message->id === $messageAId) {
                $groupAIds = array_merge($groupAIds, $job->contactIds);
            } else {
                $groupBIds = array_merge($groupBIds, $job->contactIds);
            }
        }

        $allIds = array_merge($groupAIds, $groupBIds);
        sort($allIds);

        return [
            'all_ids' => $allIds,
            'group_a_ids' => $groupAIds,
            'group_b_ids' => $groupBIds,
            'jobs' => $jobs,
        ];
    }

    // ---------------------------------------------------------------
    // Preview and send produce the same count
    // ---------------------------------------------------------------

    public function test_preview_and_send_match_with_no_filters(): void
    {
        $user = User::factory()->create();
        Contact::factory()->count(5)->for($user)->create();
        Contact::factory()->for($user)->create(['unsubscribed_at' => now()]);

        $this->setupTransportMock();

        $previewCount = $this->getPreviewCount($user, []);

        $newsletter = $this->createQueuedNewsletter($user);
        $sendCount = $this->getSendCount($newsletter, $this->fakeBatch());

        $this->assertSame(5, $previewCount);
        $this->assertSame($previewCount, $sendCount);
    }

    public function test_preview_and_send_match_with_list_filter(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();

        $inList = Contact::factory()->count(3)->for($user)->create();
        $inList->each(fn (Contact $c) => $c->lists()->attach($list));

        Contact::factory()->count(2)->for($user)->create(); // not in list

        $filters = [
            [
                'connector' => 'and',
                'category' => 'lists',
                'mode' => 'any',
                'values' => [$list->id],
            ],
        ];

        $this->setupTransportMock();

        $previewCount = $this->getPreviewCount($user, $filters);

        $newsletter = $this->createQueuedNewsletter($user, $filters);
        $sendCount = $this->getSendCount($newsletter, $this->fakeBatch());

        $this->assertSame(3, $previewCount);
        $this->assertSame($previewCount, $sendCount);
    }

    public function test_preview_and_send_match_with_tag_filter(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create(['name' => 'vip']);

        $tagged = Contact::factory()->count(4)->for($user)->create();
        $tagged->each(fn (Contact $c) => $c->tags()->attach($tag));

        Contact::factory()->count(2)->for($user)->create(); // no tag

        $filters = [
            [
                'connector' => 'and',
                'category' => 'tags',
                'mode' => 'any',
                'values' => ['vip'],
            ],
        ];

        $this->setupTransportMock();

        $previewCount = $this->getPreviewCount($user, $filters);

        $newsletter = $this->createQueuedNewsletter($user, $filters);
        $sendCount = $this->getSendCount($newsletter, $this->fakeBatch());

        $this->assertSame(4, $previewCount);
        $this->assertSame($previewCount, $sendCount);
    }

    public function test_preview_and_send_match_with_attribute_filter(): void
    {
        $user = User::factory()->create();

        Contact::factory()->count(3)->for($user)->create([
            'extra_attributes' => ['plan' => 'pro'],
        ]);

        Contact::factory()->count(2)->for($user)->create([
            'extra_attributes' => ['plan' => 'free'],
        ]);

        $filters = [
            [
                'connector' => 'and',
                'category' => 'attributes',
                'mode' => 'all',
                'values' => [['key' => 'plan', 'value' => 'pro']],
            ],
        ];

        $this->setupTransportMock();

        $previewCount = $this->getPreviewCount($user, $filters);

        $newsletter = $this->createQueuedNewsletter($user, $filters);
        $sendCount = $this->getSendCount($newsletter, $this->fakeBatch());

        $this->assertSame(3, $previewCount);
        $this->assertSame($previewCount, $sendCount);
    }

    public function test_preview_and_send_match_with_combined_filters(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create(['name' => 'premium']);

        $matchesBoth = Contact::factory()->count(2)->for($user)->create();
        $matchesBoth->each(function (Contact $c) use ($list, $tag): void {
            $c->lists()->attach($list);
            $c->tags()->attach($tag);
        });

        $listOnly = Contact::factory()->for($user)->create();
        $listOnly->lists()->attach($list);

        $tagOnly = Contact::factory()->for($user)->create();
        $tagOnly->tags()->attach($tag);

        $filters = [
            [
                'connector' => 'and',
                'category' => 'lists',
                'mode' => 'any',
                'values' => [$list->id],
            ],
            [
                'connector' => 'and',
                'category' => 'tags',
                'mode' => 'any',
                'values' => ['premium'],
            ],
        ];

        $this->setupTransportMock();

        $previewCount = $this->getPreviewCount($user, $filters);

        $newsletter = $this->createQueuedNewsletter($user, $filters);
        $sendCount = $this->getSendCount($newsletter, $this->fakeBatch());

        $this->assertSame(2, $previewCount);
        $this->assertSame($previewCount, $sendCount);
    }

    // ---------------------------------------------------------------
    // Every matching contact gets a chunk job dispatched
    // ---------------------------------------------------------------

    public function test_all_contacts_are_dispatched_with_no_filters(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $contacts = Contact::factory()->count(5)->for($user)->create();
        Contact::factory()->for($user)->create(['unsubscribed_at' => now()]);

        $newsletter = $this->createQueuedNewsletter($user);
        $result = $this->captureDispatchedIds($newsletter);

        $expectedIds = $contacts->pluck('id')->sort()->values()->all();

        $this->assertSame($expectedIds, $result['ids']);
    }

    public function test_all_filtered_contacts_are_dispatched_with_list_filter(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();

        $inList = Contact::factory()->count(4)->for($user)->create();
        $inList->each(fn (Contact $c) => $c->lists()->attach($list));

        Contact::factory()->count(3)->for($user)->create(); // not in list

        $filters = [
            [
                'connector' => 'and',
                'category' => 'lists',
                'mode' => 'any',
                'values' => [$list->id],
            ],
        ];

        $newsletter = $this->createQueuedNewsletter($user, $filters);
        $result = $this->captureDispatchedIds($newsletter);

        $expectedIds = $inList->pluck('id')->sort()->values()->all();

        $this->assertSame($expectedIds, $result['ids']);
        $this->assertCount(4, $result['ids']);
    }

    public function test_all_filtered_contacts_are_dispatched_with_tag_filter(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $tag = Tag::factory()->for($user)->create(['name' => 'vip']);

        $tagged = Contact::factory()->count(3)->for($user)->create();
        $tagged->each(fn (Contact $c) => $c->tags()->attach($tag));

        Contact::factory()->count(2)->for($user)->create(); // no tag

        $filters = [
            [
                'connector' => 'and',
                'category' => 'tags',
                'mode' => 'any',
                'values' => ['vip'],
            ],
        ];

        $newsletter = $this->createQueuedNewsletter($user, $filters);
        $result = $this->captureDispatchedIds($newsletter);

        $expectedIds = $tagged->pluck('id')->sort()->values()->all();

        $this->assertSame($expectedIds, $result['ids']);
    }

    public function test_dispatched_count_matches_total_contacts_reached(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();

        $contacts = Contact::factory()->count(10)->for($user)->create();
        $contacts->each(fn (Contact $c) => $c->lists()->attach($list));

        $filters = [
            [
                'connector' => 'and',
                'category' => 'lists',
                'mode' => 'any',
                'values' => [$list->id],
            ],
        ];

        $newsletter = $this->createQueuedNewsletter($user, $filters);
        $result = $this->captureDispatchedIds($newsletter);

        $this->assertSame($newsletter->fresh()->total_contacts_reached, count($result['ids']));
    }

    // ---------------------------------------------------------------
    // Small chunk size dispatches all contacts across multiple chunks
    // ---------------------------------------------------------------

    public function test_all_contacts_dispatched_across_multiple_small_chunks(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();

        $contacts = Contact::factory()->count(15)->for($user)->create();
        $contacts->each(fn (Contact $c) => $c->lists()->attach($list));

        $filters = [
            [
                'connector' => 'and',
                'category' => 'lists',
                'mode' => 'any',
                'values' => [$list->id],
            ],
        ];

        $newsletter = $this->createQueuedNewsletter($user, $filters);
        $result = $this->captureDispatchedIds($newsletter, chunkSize: 3);

        $expectedIds = $contacts->pluck('id')->sort()->values()->all();

        $this->assertSame($expectedIds, $result['ids']);
        $this->assertSame(15, count($result['ids']));
        $this->assertSame(5, $result['chunks']); // 15 / 3 = 5 chunks
    }

    public function test_all_contacts_dispatched_with_chunk_size_of_one(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $contacts = Contact::factory()->count(7)->for($user)->create();

        $newsletter = $this->createQueuedNewsletter($user);
        $result = $this->captureDispatchedIds($newsletter, chunkSize: 1);

        $expectedIds = $contacts->pluck('id')->sort()->values()->all();

        $this->assertSame($expectedIds, $result['ids']);
        $this->assertSame(7, count($result['ids']));
    }

    // ---------------------------------------------------------------
    // No duplicates in dispatched contact IDs
    // ---------------------------------------------------------------

    public function test_no_duplicate_contact_ids_are_dispatched(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create(['name' => 'active']);

        $contacts = Contact::factory()->count(10)->for($user)->create();
        $contacts->each(function (Contact $c) use ($list, $tag): void {
            $c->lists()->attach($list);
            $c->tags()->attach($tag);
        });

        $filters = [
            [
                'connector' => 'and',
                'category' => 'lists',
                'mode' => 'any',
                'values' => [$list->id],
            ],
            [
                'connector' => 'and',
                'category' => 'tags',
                'mode' => 'any',
                'values' => ['active'],
            ],
        ];

        $newsletter = $this->createQueuedNewsletter($user, $filters);
        $result = $this->captureDispatchedIds($newsletter, chunkSize: 3);

        $this->assertSame(count($result['ids']), count(array_unique($result['ids'])));
        $this->assertCount(10, $result['ids']);
    }

    // ---------------------------------------------------------------
    // Excluded contacts are not dispatched
    // ---------------------------------------------------------------

    public function test_unsubscribed_contacts_are_excluded(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        Contact::factory()->count(3)->for($user)->create();
        Contact::factory()->count(2)->for($user)->create(['unsubscribed_at' => now()]);

        $newsletter = $this->createQueuedNewsletter($user);
        $result = $this->captureDispatchedIds($newsletter);

        $this->assertCount(3, $result['ids']);
    }

    public function test_contacts_already_mailed_for_newsletter_are_excluded_from_send_but_counted_in_preview(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $contacts = Contact::factory()->count(5)->for($user)->create();

        $this->setupTransportMock();

        $newsletter = $this->createQueuedNewsletter($user);

        ContactMail::factory()
            ->for($contacts[0], 'contact')
            ->forNewsletter($newsletter)
            ->sent()
            ->create();

        ContactMail::factory()
            ->for($contacts[1], 'contact')
            ->forNewsletter($newsletter)
            ->sent()
            ->create();

        // Preview doesn't know about the newsletter, so it counts all 5
        $previewCount = $this->getPreviewCount($user, []);
        $this->assertSame(5, $previewCount);

        // Send excludes the 2 already-mailed contacts
        $dispatchedIds = [];
        $batch = Mockery::mock(Batch::class);
        $batch->shouldReceive('add')->andReturnUsing(function (array $jobs) use (&$dispatchedIds, $batch) {
            foreach ($jobs as $job) {
                $dispatchedIds = array_merge($dispatchedIds, $job->contactIds);
            }

            return $batch;
        });

        app(SendNewsletterService::class)->send($newsletter, $batch);

        $this->assertCount(3, $dispatchedIds);
        $this->assertNotContains($contacts[0]->id, $dispatchedIds);
        $this->assertNotContains($contacts[1]->id, $dispatchedIds);
    }

    public function test_contacts_outside_filtered_list_are_not_dispatched(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $targetList = MailList::factory()->for($user)->create();
        $otherList = MailList::factory()->for($user)->create();

        $inTarget = Contact::factory()->count(3)->for($user)->create();
        $inTarget->each(fn (Contact $c) => $c->lists()->attach($targetList));

        $inOther = Contact::factory()->count(4)->for($user)->create();
        $inOther->each(fn (Contact $c) => $c->lists()->attach($otherList));

        Contact::factory()->count(2)->for($user)->create();

        $filters = [
            [
                'connector' => 'and',
                'category' => 'lists',
                'mode' => 'any',
                'values' => [$targetList->id],
            ],
        ];

        $newsletter = $this->createQueuedNewsletter($user, $filters);
        $result = $this->captureDispatchedIds($newsletter);

        $expectedIds = $inTarget->pluck('id')->sort()->values()->all();

        $this->assertSame($expectedIds, $result['ids']);
    }

    // ---------------------------------------------------------------
    // Unified query produces consistent results
    // ---------------------------------------------------------------

    public function test_unified_query_method_returns_same_count_as_preview_endpoint(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create(['name' => 'beta']);

        $matching = Contact::factory()->count(5)->for($user)->create();
        $matching->each(function (Contact $c) use ($list, $tag): void {
            $c->lists()->attach($list);
            $c->tags()->attach($tag);
        });

        $listOnly = Contact::factory()->count(3)->for($user)->create();
        $listOnly->each(fn (Contact $c) => $c->lists()->attach($list));

        Contact::factory()->count(2)->for($user)->create();

        $filters = [
            [
                'connector' => 'and',
                'category' => 'lists',
                'mode' => 'any',
                'values' => [$list->id],
            ],
            [
                'connector' => 'and',
                'category' => 'tags',
                'mode' => 'any',
                'values' => ['beta'],
            ],
        ];

        $previewCount = $this->getPreviewCount($user, $filters);

        /** @var ContactFilterService $filterService */
        $filterService = app(ContactFilterService::class);
        $queryCount = $filterService->newsletterRecipientsQuery($user->client_id, $filters)->count();

        $this->assertSame(5, $previewCount);
        $this->assertSame($previewCount, $queryCount);
    }

    public function test_unified_query_with_newsletter_excludes_already_mailed(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $contacts = Contact::factory()->count(5)->for($user)->create();

        $this->setupTransportMock();
        $newsletter = $this->createQueuedNewsletter($user);

        ContactMail::factory()
            ->for($contacts[0], 'contact')
            ->forNewsletter($newsletter)
            ->sent()
            ->create();

        /** @var ContactFilterService $filterService */
        $filterService = app(ContactFilterService::class);

        $withoutNewsletter = $filterService->newsletterRecipientsQuery($user->client_id, [])->count();
        $withNewsletter = $filterService->newsletterRecipientsQuery($user->client_id, [], $newsletter)->count();

        $this->assertSame(5, $withoutNewsletter);
        $this->assertSame(4, $withNewsletter);
    }

    // ---------------------------------------------------------------
    // Mode "none" filter works consistently
    // ---------------------------------------------------------------

    public function test_preview_and_send_match_with_list_none_filter(): void
    {
        $user = User::factory()->create();
        $excludedList = MailList::factory()->for($user)->create();

        $inList = Contact::factory()->count(2)->for($user)->create();
        $inList->each(fn (Contact $c) => $c->lists()->attach($excludedList));

        Contact::factory()->count(3)->for($user)->create(); // not in list

        $filters = [
            [
                'connector' => 'and',
                'category' => 'lists',
                'mode' => 'none',
                'values' => [$excludedList->id],
            ],
        ];

        $this->setupTransportMock();

        $previewCount = $this->getPreviewCount($user, $filters);

        $newsletter = $this->createQueuedNewsletter($user, $filters);
        $sendCount = $this->getSendCount($newsletter, $this->fakeBatch());

        $this->assertSame(3, $previewCount);
        $this->assertSame($previewCount, $sendCount);
    }

    public function test_preview_and_send_match_with_tag_none_filter(): void
    {
        $user = User::factory()->create();
        $excludedTag = Tag::factory()->for($user)->create(['name' => 'churned']);

        $tagged = Contact::factory()->count(2)->for($user)->create();
        $tagged->each(fn (Contact $c) => $c->tags()->attach($excludedTag));

        Contact::factory()->count(4)->for($user)->create();

        $filters = [
            [
                'connector' => 'and',
                'category' => 'tags',
                'mode' => 'none',
                'values' => ['churned'],
            ],
        ];

        $this->setupTransportMock();

        $previewCount = $this->getPreviewCount($user, $filters);

        $newsletter = $this->createQueuedNewsletter($user, $filters);
        $sendCount = $this->getSendCount($newsletter, $this->fakeBatch());

        $this->assertSame(4, $previewCount);
        $this->assertSame($previewCount, $sendCount);
    }

    // ---------------------------------------------------------------
    // Mode "all" filter works consistently
    // ---------------------------------------------------------------

    public function test_preview_and_send_match_with_list_all_filter(): void
    {
        $user = User::factory()->create();
        $listA = MailList::factory()->for($user)->create();
        $listB = MailList::factory()->for($user)->create();

        $inBoth = Contact::factory()->count(2)->for($user)->create();
        $inBoth->each(function (Contact $c) use ($listA, $listB): void {
            $c->lists()->attach([$listA->id, $listB->id]);
        });

        $inOneOnly = Contact::factory()->for($user)->create();
        $inOneOnly->lists()->attach($listA);

        $filters = [
            [
                'connector' => 'and',
                'category' => 'lists',
                'mode' => 'all',
                'values' => [$listA->id, $listB->id],
            ],
        ];

        $this->setupTransportMock();

        $previewCount = $this->getPreviewCount($user, $filters);

        $newsletter = $this->createQueuedNewsletter($user, $filters);
        $sendCount = $this->getSendCount($newsletter, $this->fakeBatch());

        $this->assertSame(2, $previewCount);
        $this->assertSame($previewCount, $sendCount);
    }

    // ---------------------------------------------------------------
    // "or" connector between filter groups
    // ---------------------------------------------------------------

    public function test_preview_and_send_match_with_or_connector(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create(['name' => 'vip']);

        // In list only
        $inList = Contact::factory()->count(2)->for($user)->create();
        $inList->each(fn (Contact $c) => $c->lists()->attach($list));

        // Tagged only
        $tagged = Contact::factory()->count(3)->for($user)->create();
        $tagged->each(fn (Contact $c) => $c->tags()->attach($tag));

        // Neither
        Contact::factory()->count(2)->for($user)->create();

        $filters = [
            [
                'connector' => 'and',
                'category' => 'lists',
                'mode' => 'any',
                'values' => [$list->id],
            ],
            [
                'connector' => 'or',
                'category' => 'tags',
                'mode' => 'any',
                'values' => ['vip'],
            ],
        ];

        $this->setupTransportMock();

        $previewCount = $this->getPreviewCount($user, $filters);

        $newsletter = $this->createQueuedNewsletter($user, $filters);
        $sendCount = $this->getSendCount($newsletter, $this->fakeBatch());

        // 2 in list + 3 tagged = 5 (no overlap)
        $this->assertSame(5, $previewCount);
        $this->assertSame($previewCount, $sendCount);
    }

    public function test_all_contacts_dispatched_with_or_connector(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create(['name' => 'beta']);

        $inList = Contact::factory()->count(2)->for($user)->create();
        $inList->each(fn (Contact $c) => $c->lists()->attach($list));

        $tagged = Contact::factory()->count(3)->for($user)->create();
        $tagged->each(fn (Contact $c) => $c->tags()->attach($tag));

        Contact::factory()->count(2)->for($user)->create();

        $filters = [
            [
                'connector' => 'and',
                'category' => 'lists',
                'mode' => 'any',
                'values' => [$list->id],
            ],
            [
                'connector' => 'or',
                'category' => 'tags',
                'mode' => 'any',
                'values' => ['beta'],
            ],
        ];

        $newsletter = $this->createQueuedNewsletter($user, $filters);
        $result = $this->captureDispatchedIds($newsletter, chunkSize: 2);

        $expectedIds = $inList->pluck('id')->merge($tagged->pluck('id'))->sort()->values()->all();

        $this->assertSame($expectedIds, $result['ids']);
        $this->assertCount(5, $result['ids']);
    }

    // ---------------------------------------------------------------
    // Multiple values in a single list filter
    // ---------------------------------------------------------------

    public function test_preview_and_send_match_with_multiple_list_values_any_mode(): void
    {
        $user = User::factory()->create();
        $listA = MailList::factory()->for($user)->create();
        $listB = MailList::factory()->for($user)->create();

        $inA = Contact::factory()->count(2)->for($user)->create();
        $inA->each(fn (Contact $c) => $c->lists()->attach($listA));

        $inB = Contact::factory()->count(3)->for($user)->create();
        $inB->each(fn (Contact $c) => $c->lists()->attach($listB));

        Contact::factory()->count(2)->for($user)->create();

        $filters = [
            [
                'connector' => 'and',
                'category' => 'lists',
                'mode' => 'any',
                'values' => [$listA->id, $listB->id],
            ],
        ];

        $this->setupTransportMock();

        $previewCount = $this->getPreviewCount($user, $filters);

        $newsletter = $this->createQueuedNewsletter($user, $filters);
        $sendCount = $this->getSendCount($newsletter, $this->fakeBatch());

        $this->assertSame(5, $previewCount);
        $this->assertSame($previewCount, $sendCount);
    }

    // ---------------------------------------------------------------
    // Attribute filter "none" mode
    // ---------------------------------------------------------------

    public function test_preview_and_send_match_with_attribute_none_filter(): void
    {
        $user = User::factory()->create();

        Contact::factory()->count(2)->for($user)->create([
            'extra_attributes' => ['plan' => 'free'],
        ]);

        Contact::factory()->count(3)->for($user)->create([
            'extra_attributes' => ['plan' => 'pro'],
        ]);

        $filters = [
            [
                'connector' => 'and',
                'category' => 'attributes',
                'mode' => 'none',
                'values' => [['key' => 'plan', 'value' => 'free']],
            ],
        ];

        $this->setupTransportMock();

        $previewCount = $this->getPreviewCount($user, $filters);

        $newsletter = $this->createQueuedNewsletter($user, $filters);
        $sendCount = $this->getSendCount($newsletter, $this->fakeBatch());

        $this->assertSame(3, $previewCount);
        $this->assertSame($previewCount, $sendCount);
    }

    // ---------------------------------------------------------------
    // AB test dispatches every contact with shuffle and split
    // ---------------------------------------------------------------

    public function test_ab_test_dispatches_all_contacts(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $contacts = Contact::factory()->count(10)->for($user)->create();

        $newsletter = $this->createQueuedAbNewsletter($user);
        $result = $this->captureAbDispatchedIds($newsletter);

        $expectedIds = $contacts->pluck('id')->sort()->values()->all();

        $this->assertSame($expectedIds, $result['all_ids']);
    }

    public function test_ab_test_splits_contacts_into_two_groups(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        Contact::factory()->count(10)->for($user)->create();

        $newsletter = $this->createQueuedAbNewsletter($user);
        $result = $this->captureAbDispatchedIds($newsletter);

        $this->assertNotEmpty($result['group_a_ids']);
        $this->assertNotEmpty($result['group_b_ids']);

        // Both groups together should equal all contacts
        $combined = array_merge($result['group_a_ids'], $result['group_b_ids']);
        sort($combined);
        $this->assertSame($result['all_ids'], $combined);

        // No overlap between groups
        $this->assertEmpty(array_intersect($result['group_a_ids'], $result['group_b_ids']));
    }

    public function test_ab_test_groups_are_roughly_equal(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        Contact::factory()->count(20)->for($user)->create();

        $newsletter = $this->createQueuedAbNewsletter($user);
        $result = $this->captureAbDispatchedIds($newsletter);

        $countA = count($result['group_a_ids']);
        $countB = count($result['group_b_ids']);

        // With ceil split, A gets at most 1 more than B per chunk
        $this->assertSame(20, $countA + $countB);
        $this->assertLessThanOrEqual(1, abs($countA - $countB));
    }

    public function test_ab_test_uses_double_chunk_size_for_iteration(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        Contact::factory()->count(12)->for($user)->create();

        // chunk_size=4 → iteration uses 8 → 12 contacts = 2 iteration chunks (8+4)
        // Each iteration chunk produces 2 jobs (A+B) → 4 jobs total
        $newsletter = $this->createQueuedAbNewsletter($user);
        $result = $this->captureAbDispatchedIds($newsletter, chunkSize: 4);

        $this->assertSame(12, count($result['all_ids']));
        // 2 iteration chunks × 2 groups = 4 jobs
        $this->assertCount(4, $result['jobs']);
    }

    public function test_ab_test_dispatches_all_contacts_with_list_filter(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();

        $inList = Contact::factory()->count(8)->for($user)->create();
        $inList->each(fn (Contact $c) => $c->lists()->attach($list));

        Contact::factory()->count(4)->for($user)->create(); // not in list

        $filters = [
            [
                'connector' => 'and',
                'category' => 'lists',
                'mode' => 'any',
                'values' => [$list->id],
            ],
        ];

        $newsletter = $this->createQueuedAbNewsletter($user, $filters);
        $result = $this->captureAbDispatchedIds($newsletter, chunkSize: 3);

        $expectedIds = $inList->pluck('id')->sort()->values()->all();

        $this->assertSame($expectedIds, $result['all_ids']);
        $this->assertEmpty(array_intersect($result['group_a_ids'], $result['group_b_ids']));
    }

    public function test_ab_test_with_odd_number_of_contacts(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        Contact::factory()->count(7)->for($user)->create();

        $newsletter = $this->createQueuedAbNewsletter($user);
        $result = $this->captureAbDispatchedIds($newsletter);

        $this->assertSame(7, count($result['all_ids']));
        // ceil(7/2) = 4 for A, 3 for B
        $this->assertCount(4, $result['group_a_ids']);
        $this->assertCount(3, $result['group_b_ids']);
    }
}
