<?php

namespace Tests\Feature;

use App\Enums\NewsletterStatus;
use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NewsletterScheduledActionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, bool}>
     */
    public static function mutableStatusProvider(): array
    {
        return [
            'draft' => ['draft', true],
            'scheduled' => ['scheduled', true],
            'queued' => ['queued', false],
            'ab_testing' => ['ab_testing', false],
            'sent' => ['sent', false],
            'failed' => ['failed', false],
        ];
    }

    #[DataProvider('mutableStatusProvider')]
    public function test_the_show_page_says_if_the_newsletter_can_change(string $status, bool $isMutable): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create(['status' => $status]);

        $this->actingAs($user)->get(route('newsletters.show', $newsletter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('newsletter.is_mutable', $isMutable));
    }

    public function test_a_scheduled_newsletter_can_be_unscheduled(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->scheduled()->create();

        $this->actingAs($user)
            ->from(route('newsletters.show', $newsletter))
            ->delete(route('newsletters.unschedule', $newsletter))
            ->assertRedirect(route('newsletters.show', $newsletter))
            ->assertSessionHas('success');

        $newsletter = Newsletter::withoutGlobalScopes()->findOrFail($newsletter->id);
        $this->assertSame(NewsletterStatus::Draft, $newsletter->status);
        $this->assertNull($newsletter->scheduled_at);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notScheduledStatusProvider(): array
    {
        return [
            'draft' => ['draft'],
            'queued' => ['queued'],
            'ab_testing' => ['ab_testing'],
            'sent' => ['sent'],
            'failed' => ['failed'],
        ];
    }

    #[DataProvider('notScheduledStatusProvider')]
    public function test_only_a_scheduled_newsletter_can_be_unscheduled(string $status): void
    {
        $user = User::factory()->create();
        $scheduledAt = now()->subHour()->startOfSecond();
        $newsletter = Newsletter::factory()->for($user)->create([
            'status' => $status,
            'scheduled_at' => $scheduledAt,
        ]);

        $this->actingAs($user)
            ->from(route('newsletters.show', $newsletter))
            ->delete(route('newsletters.unschedule', $newsletter))
            ->assertRedirect(route('newsletters.show', $newsletter))
            ->assertSessionHas('error', 'Only a scheduled newsletter can be unscheduled.');

        $newsletter = Newsletter::withoutGlobalScopes()->findOrFail($newsletter->id);
        $this->assertSame($status, $newsletter->status->value);
        $this->assertTrue($scheduledAt->equalTo($newsletter->scheduled_at));
    }

    public function test_a_user_cannot_unschedule_the_newsletter_of_another_client(): void
    {
        $owner = User::factory()->create();
        $newsletter = Newsletter::factory()->for($owner)->scheduled()->create();

        $this->actingAs(User::factory()->create())
            ->delete(route('newsletters.unschedule', $newsletter))
            ->assertNotFound();

        $this->assertSame(NewsletterStatus::Scheduled, Newsletter::withoutGlobalScopes()->findOrFail($newsletter->id)->status);
    }

    public function test_a_scheduled_newsletter_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->scheduled()->create();

        $this->actingAs($user)
            ->delete(route('newsletters.destroy', $newsletter))
            ->assertRedirect(route('newsletters.index'));

        $this->assertNull(Newsletter::withoutGlobalScopes()->find($newsletter->id));
    }
}
