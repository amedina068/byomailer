<?php

namespace Tests\Feature;

use App\Jobs\DispatchNewsletterBatchJob;
use App\Models\EmailIdentity;
use App\Models\MailList;
use App\Models\Message;
use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NewsletterWriteGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function lockedStatusProvider(): array
    {
        return [
            'queued' => ['queued'],
            'ab_testing' => ['ab_testing'],
            'sent' => ['sent'],
            'failed' => ['failed'],
        ];
    }

    #[DataProvider('lockedStatusProvider')]
    public function test_content_update_is_refused_for_a_locked_newsletter(string $status): void
    {
        [$user, $newsletter] = $this->lockedNewsletter($status);
        $before = $this->snapshot($newsletter);

        $response = $this->actingAs($user)->patch(route('newsletters.content.update', $newsletter), [
            'title' => 'Rewritten title',
            'subject' => 'Rewritten subject',
            'html_content' => '<p>Rewritten body</p>',
        ]);

        $this->assertSame($before, $this->snapshot($newsletter));
        $response->assertNotFound();
    }

    #[DataProvider('lockedStatusProvider')]
    public function test_filters_update_is_refused_for_a_locked_newsletter(string $status): void
    {
        [$user, $newsletter] = $this->lockedNewsletter($status);
        $list = MailList::factory()->for($user)->create();
        $before = $this->snapshot($newsletter);

        $response = $this->actingAs($user)->patch(route('newsletters.filters.update', $newsletter), [
            'filters' => [[
                'connector' => 'and',
                'category' => 'lists',
                'mode' => 'any',
                'values' => [$list->id],
            ]],
        ]);

        $this->assertSame($before, $this->snapshot($newsletter));
        $response->assertNotFound();
    }

    #[DataProvider('lockedStatusProvider')]
    public function test_variables_update_is_refused_for_a_locked_newsletter(string $status): void
    {
        [$user, $newsletter] = $this->lockedNewsletter($status);
        $before = $this->snapshot($newsletter);

        $response = $this->actingAs($user)->patch(route('newsletters.variables.update', $newsletter), [
            'content_variables' => ['first_name' => 'Friend'],
        ]);

        $this->assertSame($before, $this->snapshot($newsletter));
        $response->assertNotFound();
    }

    #[DataProvider('lockedStatusProvider')]
    public function test_send_now_is_refused_for_a_locked_newsletter(string $status): void
    {
        Bus::fake();
        [$user, $newsletter] = $this->lockedNewsletter($status);
        $identity = EmailIdentity::factory()->for($user)->create();
        $before = $this->snapshot($newsletter);

        $response = $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'send_now',
            'email_identity_id' => $identity->id,
        ]);

        $this->assertSame($before, $this->snapshot($newsletter));
        Bus::assertNotDispatched(DispatchNewsletterBatchJob::class);
        $response->assertNotFound();
    }

    #[DataProvider('lockedStatusProvider')]
    public function test_schedule_is_refused_for_a_locked_newsletter(string $status): void
    {
        Bus::fake();
        [$user, $newsletter] = $this->lockedNewsletter($status);
        $identity = EmailIdentity::factory()->for($user)->create();
        $before = $this->snapshot($newsletter);

        $response = $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'schedule',
            'scheduled_for' => now($user->client->timezone)->addDay()->format('Y-m-d\TH:i'),
            'email_identity_id' => $identity->id,
        ]);

        $this->assertSame($before, $this->snapshot($newsletter));
        Bus::assertNotDispatched(DispatchNewsletterBatchJob::class);
        $response->assertNotFound();
    }

    public function test_a_scheduled_newsletter_can_still_change_its_content_and_variables(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->scheduled()->create();

        $this->actingAs($user)->patch(route('newsletters.content.update', $newsletter), [
            'title' => 'New title',
            'subject' => 'Hello {{ first_name }}',
            'html_content' => '<p>New body</p>',
        ])->assertRedirect(route('newsletters.variables.show', $newsletter));

        $this->actingAs($user)->get(route('newsletters.variables.show', $newsletter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('initialStep', 'variables'));

        $this->actingAs($user)->patch(route('newsletters.variables.update', $newsletter), [
            'content_variables' => ['first_name' => 'Friend'],
        ])->assertRedirect(route('newsletters.filters.show', $newsletter));

        $newsletter = Newsletter::withoutGlobalScopes()->with('message')->findOrFail($newsletter->id);
        $this->assertSame('Hello {{ first_name }}', $newsletter->message?->subject);
        $this->assertSame(['first_name' => 'Friend'], $newsletter->content_variables);
    }

    public function test_the_internal_title_of_a_sent_newsletter_can_change(): void
    {
        [$user, $newsletter] = $this->lockedNewsletter('sent');

        $this->actingAs($user)->patch(route('newsletters.title.update', $newsletter), [
            'title' => 'Renamed after send',
        ])->assertRedirect();

        $this->assertSame('Renamed after send', Newsletter::withoutGlobalScopes()->findOrFail($newsletter->id)->title);
    }

    /**
     * @return array{User, Newsletter}
     */
    private function lockedNewsletter(string $status): array
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create([
            'status' => $status,
            'scheduled_at' => now()->subHour(),
            'sent_at' => $status === 'sent' ? now()->subMinutes(30) : null,
        ]);

        return [$user, $newsletter];
    }

    /**
     * @return array{newsletter: array<string, mixed>, messages: array<int, array<string, mixed>>}
     */
    private function snapshot(Newsletter $newsletter): array
    {
        return [
            'newsletter' => Newsletter::withoutGlobalScopes()->findOrFail($newsletter->id)->getAttributes(),
            'messages' => Message::withoutGlobalScopes()
                ->where('messageable_type', Newsletter::class)
                ->where('messageable_id', $newsletter->id)
                ->orderBy('id')
                ->get()
                ->map(fn (Message $message): array => $message->getAttributes())
                ->all(),
        ];
    }
}
