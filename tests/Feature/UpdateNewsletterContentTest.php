<?php

namespace Tests\Feature;

use App\Actions\UpdateNewsletterContent;
use App\Enums\AbVariant;
use App\Models\EmailTemplate;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\Segment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UpdateNewsletterContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_changes_nothing_when_the_scheduler_queued_the_newsletter_first(): void
    {
        [$user, $staleCopy] = $this->newsletter('scheduled');
        $this->claimAsTheScheduler($staleCopy);

        $errors = $this->errorsOf(fn () => $this->update($user, $staleCopy, [
            'title' => 'Changed while it sends',
            'subject' => 'Changed subject',
        ]));

        $this->assertArrayHasKey('status', $errors);
        $stored = Newsletter::withoutGlobalScopes()->findOrFail($staleCopy->id);
        $this->assertNotSame('Changed while it sends', $stored->title);
        $this->assertNotSame('Changed subject', $stored->message()->withoutGlobalScopes()->value('subject'));
    }

    public function test_it_refuses_an_edit_that_leaves_a_scheduled_newsletter_without_content(): void
    {
        [$user, $newsletter] = $this->newsletter('scheduled');
        $subject = $newsletter->message->subject;

        $errors = $this->errorsOf(fn () => $this->update($user, $newsletter, [
            'subject' => '',
            'html_content' => '<p></p>',
        ]));

        $this->assertArrayHasKey('subject', $errors);
        $this->assertArrayHasKey('html_content', $errors);
        $this->assertSame($subject, $newsletter->message()->value('subject'));
    }

    public function test_a_draft_can_keep_a_blank_subject(): void
    {
        [$user, $newsletter] = $this->newsletter('draft');

        $this->update($user, $newsletter, ['subject' => '']);

        $this->assertSame('', $newsletter->message()->value('subject'));
    }

    public function test_it_refuses_a_newsletter_that_became_scheduled_when_scheduled_edits_are_not_allowed(): void
    {
        [$user, $staleCopy] = $this->newsletter('draft');
        Newsletter::query()->whereKey($staleCopy->id)->update(['status' => 'scheduled', 'scheduled_at' => now()->addDay()]);

        $errors = $this->errorsOf(fn () => app(UpdateNewsletterContent::class)->handle($user, $staleCopy, ['title' => 'Unconfirmed'], scheduledAllowed: false));

        $this->assertSame([UpdateNewsletterContent::SCHEDULED_NEEDS_CONFIRMATION], $errors['status']);
        $this->assertNotSame('Unconfirmed', $staleCopy->fresh()->title);
    }

    public function test_filters_clear_the_segment_and_a_segment_clears_the_filters(): void
    {
        [$user, $newsletter] = $this->newsletter('draft');
        $segment = Segment::factory()->for($user->client)->create();
        $list = MailList::factory()->for($user)->create();

        $this->update($user, $newsletter, ['segment_id' => $segment->id, 'filters' => []]);
        $this->assertSame($segment->id, $newsletter->fresh()->segment_id);

        $this->update($user, $newsletter, ['filters' => [[
            'connector' => 'and', 'category' => 'lists', 'mode' => 'any', 'values' => [$list->id],
        ]]]);

        $newsletter->refresh();
        $this->assertNull($newsletter->segment_id);
        $this->assertSame([$list->id], $newsletter->filters[0]['values']);
    }

    public function test_the_template_slot_has_one_rule(): void
    {
        [$user, $newsletter] = $this->newsletter('draft');
        $template = EmailTemplate::factory()->for($user->client)->create(['html_content' => '<header>H</header>{{ content }}']);

        $this->update($user, $newsletter, ['email_template_id' => $template->id, 'html_content' => '<p>Raw body</p>']);
        $this->assertSame('<p>Raw body</p>', $newsletter->message()->value('template_slot_content'));

        $this->update($user, $newsletter, ['html_content' => '<header>H</header><div data-slot="content"><p>Slot body</p></div>']);
        $this->assertSame('<p>Slot body</p>', $newsletter->message()->value('template_slot_content'));

        $this->update($user, $newsletter, ['email_template_id' => null]);
        $this->assertNull($newsletter->message()->value('template_slot_content'));
    }

    public function test_variant_b_copies_the_sender_and_the_template_of_message_a(): void
    {
        [$user, $newsletter] = $this->newsletter('draft');
        $template = EmailTemplate::factory()->for($user->client)->create(['html_content' => '{{ content }}']);

        $this->update($user, $newsletter, [
            'ab_test_enabled' => true,
            'email_template_id' => $template->id,
            'subject_b' => 'Subject B',
            'html_content_b' => '<p>Body B</p>',
        ]);

        $newsletter->refresh();
        $this->assertSame(AbVariant::A, $newsletter->message->variant);
        $this->assertSame('Subject B', $newsletter->messageB->subject);
        $this->assertSame($newsletter->message->email_identity_id, $newsletter->messageB->email_identity_id);
        $this->assertSame($template->id, $newsletter->messageB->email_template_id);
        $this->assertSame('<p>Body B</p>', $newsletter->messageB->template_slot_content);
        $this->assertSame('Body B', trim($newsletter->messageB->text_content));

        $this->update($user, $newsletter, ['ab_test_enabled' => false]);

        $this->assertNull($newsletter->fresh()->messageB);
    }

    public function test_the_web_content_update_reports_a_newsletter_that_the_scheduler_claimed_first(): void
    {
        [$user, $newsletter] = $this->newsletter('scheduled');
        $this->claimWhenRead($newsletter);

        $this->actingAs($user)
            ->patch(route('newsletters.content.update', $newsletter), [
                'title' => 'Changed while it sends',
                'subject' => 'Changed subject',
                'html_content' => '<p>Changed body</p>',
            ])
            ->assertSessionHasErrors('status');

        $this->assertNotSame('Changed while it sends', Newsletter::withoutGlobalScopes()->findOrFail($newsletter->id)->title);
    }

    public function test_the_web_filters_update_reports_a_newsletter_that_the_scheduler_claimed_first(): void
    {
        [$user, $newsletter] = $this->newsletter('scheduled');
        $segment = Segment::factory()->for($user->client)->create();
        $this->claimWhenRead($newsletter);

        $this->actingAs($user)
            ->patch(route('newsletters.filters.update', $newsletter), [
                'filters' => [],
                'segment_id' => $segment->id,
            ])
            ->assertSessionHasErrors('status');

        $this->assertNull(Newsletter::withoutGlobalScopes()->findOrFail($newsletter->id)->segment_id);
    }

    public function test_the_web_content_update_refuses_unsafe_or_oversized_html(): void
    {
        [$user, $newsletter] = $this->newsletter('draft');

        foreach (['html_content', 'html_content_b'] as $field) {
            $this->actingAs($user)
                ->patch(route('newsletters.content.update', $newsletter), [
                    'title' => 'Title',
                    'subject' => 'Subject',
                    'subject_b' => 'Subject B',
                    'html_content' => '<p>Body</p>',
                    'html_content_b' => '<p>Body B</p>',
                    'ab_test_enabled' => true,
                    $field => '<p>Body</p><script>alert(1)</script>',
                ])
                ->assertSessionHasErrors($field);

            $this->actingAs($user)
                ->patch(route('newsletters.content.update', $newsletter), [
                    'title' => 'Title',
                    'subject' => 'Subject',
                    'subject_b' => 'Subject B',
                    'html_content' => '<p>Body</p>',
                    'html_content_b' => '<p>Body B</p>',
                    'ab_test_enabled' => true,
                    $field => '<p>'.str_repeat('a', 524289).'</p>',
                ])
                ->assertSessionHasErrors($field);
        }
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

    /**
     * @return array{User, Newsletter}
     */
    private function newsletter(string $status): array
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);

        $newsletter = Newsletter::factory()->for($user)->create([
            'status' => $status,
            'scheduled_at' => $status === 'draft' ? null : now()->addDay(),
        ]);

        return [$user, $newsletter];
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function update(User $user, Newsletter $newsletter, array $changes): void
    {
        app(UpdateNewsletterContent::class)->handle($user, $newsletter, $changes);
    }

    /**
     * @return array<string, list<string>>
     */
    private function errorsOf(callable $callback): array
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('The update did not fail.');
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
}
