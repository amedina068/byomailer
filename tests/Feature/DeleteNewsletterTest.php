<?php

namespace Tests\Feature;

use App\Actions\DeleteNewsletter;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeleteNewsletterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function deletableStatusProvider(): array
    {
        return [
            'draft' => ['draft'],
            'scheduled' => ['scheduled'],
        ];
    }

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

    #[DataProvider('deletableStatusProvider')]
    public function test_it_deletes_the_newsletter_with_its_messages_and_contact_mails(string $status): void
    {
        $newsletter = $this->newsletterWithMail($status);

        app(DeleteNewsletter::class)->handle($newsletter);

        $this->assertNewsletterIsGone($newsletter);
    }

    #[DataProvider('lockedStatusProvider')]
    public function test_it_refuses_a_newsletter_that_is_sending_or_sent(string $status): void
    {
        $newsletter = $this->newsletterWithMail($status);

        try {
            app(DeleteNewsletter::class)->handle($newsletter);
            $this->fail('The delete did not fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertNewsletterIsKept($newsletter);
    }

    public function test_it_deletes_nothing_when_the_scheduler_queued_the_newsletter_first(): void
    {
        $staleCopy = $this->newsletterWithMail('scheduled');
        $this->claimAsTheScheduler($staleCopy);

        try {
            app(DeleteNewsletter::class)->handle($staleCopy);
            $this->fail('The delete did not fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertNewsletterIsKept($staleCopy);
    }

    public function test_the_web_delete_reports_a_newsletter_that_the_scheduler_claimed_first(): void
    {
        $newsletter = $this->newsletterWithMail('scheduled');
        $this->claimWhenRead($newsletter);

        $this->actingAs($newsletter->user)
            ->from(route('newsletters.index'))
            ->delete(route('newsletters.destroy', $newsletter))
            ->assertRedirect(route('newsletters.index'))
            ->assertSessionHas('error');

        $this->assertNewsletterIsKept($newsletter);
    }

    public function test_the_api_deletes_a_scheduled_newsletter(): void
    {
        $newsletter = $this->newsletterWithMail('scheduled');
        Sanctum::actingAs($newsletter->user, ['*']);

        $this->deleteJson("/api/v1/newsletters/{$newsletter->id}")->assertNoContent();

        $this->assertNewsletterIsGone($newsletter);
    }

    public function test_the_api_delete_reports_a_newsletter_that_the_scheduler_claimed_first(): void
    {
        $newsletter = $this->newsletterWithMail('scheduled');
        Sanctum::actingAs($newsletter->user, ['*']);
        $this->claimWhenRead($newsletter);

        $this->deleteJson("/api/v1/newsletters/{$newsletter->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertNewsletterIsKept($newsletter);
    }

    private function newsletterWithMail(string $status): Newsletter
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);

        $newsletter = Newsletter::factory()->for($user)->create([
            'status' => $status,
            'scheduled_at' => $status === 'draft' ? null : now()->addDay(),
        ]);

        ContactMail::factory()->forNewsletter($newsletter)->create([
            'contact_id' => Contact::factory()->for($user)->create()->id,
        ]);

        return $newsletter;
    }

    /**
     * The same conditional update that `contact-mails:send-due-newsletters` runs.
     */
    private function claimAsTheScheduler(Newsletter $newsletter): void
    {
        Newsletter::withoutGlobalScopes()
            ->whereKey($newsletter->id)
            ->where('status', 'scheduled')
            ->update(['status' => 'queued']);
    }

    /**
     * Run the claim of the scheduler one time, right after the request reads
     * the newsletter.
     */
    private function claimWhenRead(Newsletter $newsletter): void
    {
        $claimed = false;

        Newsletter::retrieved(function (Newsletter $retrieved) use ($newsletter, &$claimed): void {
            if ($claimed || $retrieved->id !== $newsletter->id) {
                return;
            }

            $claimed = true;
            $this->claimAsTheScheduler($newsletter);
        });
    }

    private function assertNewsletterIsGone(Newsletter $newsletter): void
    {
        $this->assertDatabaseMissing('newsletters', ['id' => $newsletter->id]);
        $this->assertDatabaseMissing('messages', ['messageable_type' => Newsletter::class, 'messageable_id' => $newsletter->id]);
        $this->assertDatabaseMissing('contact_mails', ['mailable_type' => Newsletter::class, 'mailable_id' => $newsletter->id]);
    }

    private function assertNewsletterIsKept(Newsletter $newsletter): void
    {
        $this->assertDatabaseHas('newsletters', ['id' => $newsletter->id]);
        $this->assertDatabaseHas('messages', ['messageable_type' => Newsletter::class, 'messageable_id' => $newsletter->id]);
        $this->assertDatabaseHas('contact_mails', ['mailable_type' => Newsletter::class, 'mailable_id' => $newsletter->id]);
    }
}
