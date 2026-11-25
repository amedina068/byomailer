<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use App\Services\NewsletterMailExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SequenceStepContactMailExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_respects_engagement_filters_sorting_and_includes_mail_details(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();
        foreach (['zeta', 'alpha'] as $name) {
            $contact = Contact::factory()->for($user)->create(['email' => $name.'@example.com', 'name' => $name]);
            ContactMail::factory()->for($contact)->forSequenceStep($step)->clicked()->create(['subject' => 'Step email']);
        }
        $pending = Contact::factory()->for($user)->create(['email' => 'pending@example.com']);
        ContactMail::factory()->for($pending)->forSequenceStep($step)->pending()->create();

        $response = $this->actingAs($user)->get(route('sequences.steps.contact-mails.export', [
            'sequence' => $sequence->id, 'sequenceStep' => $step->id,
            'filter' => 'clicked', 'sort' => 'email', 'direction' => 'asc',
        ]));
        $response->assertOk()
            ->assertDownload("sequence-{$sequence->id}-step-{$step->id}-contact-mails.csv")
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $rows = $this->csvRows($response->streamedContent());
        $this->assertCount(3, $rows);
        $this->assertContains('clicked_at', $rows[0]);
        $this->assertContains('failure_reason', $rows[0]);
        $this->assertSame('alpha@example.com', $rows[1][0]);
        $this->assertSame('zeta@example.com', $rows[2][0]);
        $this->assertSame('Step email', $rows[1][4]);
    }

    public function test_export_streams_all_matching_rows_across_chunks_ignoring_pagination(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();
        $contacts = Contact::factory()->count(205)->for($user)->create();
        foreach ($contacts as $contact) {
            ContactMail::factory()->for($contact)->forSequenceStep($step)->pending()->create();
        }

        $response = $this->actingAs($user)->get(route('sequences.steps.contact-mails.export', [
            'sequence' => $sequence->id, 'sequenceStep' => $step->id,
            'page' => 2, 'per_page' => 1,
        ]))->assertOk();
        $rows = $this->csvRows($response->streamedContent());
        $this->assertCount(206, $rows);
        $this->assertCount(205, array_unique(array_column(array_slice($rows, 1), 0)));
    }

    public function test_export_personalizes_subject_and_body_using_the_newsletter_export_format(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();
        Block::factory()->for($user)->create(['name' => 'footer', 'html_content' => '<p>Sent by Acme</p>']);
        $step->message->update([
            'subject' => 'Hello {{ name }}',
            'text_content' => null,
            'html_content' => '<p>Welcome {{ name }}</p>{{ block.footer }}<p>{{ unsubscribe_url }}</p>',
        ]);
        $contact = Contact::factory()->for($user)->create(['name' => 'Ada']);
        $mail = ContactMail::factory()->for($contact)->forSequenceStep($step)->create([
            'subject' => null,
            'message_id' => null,
            'template_data' => ['name' => 'Ada'],
        ]);

        $response = $this->actingAs($user)->get(route('sequences.steps.contact-mails.export', [
            'sequence' => $sequence->id, 'sequenceStep' => $step->id,
        ]))->assertOk();
        $rows = $this->csvRows($response->streamedContent());
        $this->assertSame(NewsletterMailExport::columns(), $rows[0]);
        $this->assertSame('Hello Ada', $rows[1][4]);
        $this->assertStringContainsString('Welcome Ada', $rows[1][17]);
        $this->assertStringContainsString('Sent by Acme', $rows[1][17]);
        $this->assertStringContainsString('/unsubscribe/'.$mail->uuid, $rows[1][17]);
        $this->assertStringNotContainsString('{{', $rows[1][17]);
        $this->assertStringNotContainsString('<p>', $rows[1][17]);
    }

    public function test_export_always_includes_the_full_history_even_with_a_legacy_current_only_filter(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create(['delay_days' => 1]);
        $laterStep = SequenceStep::factory()->for($sequence)->create(['delay_days' => 2]);
        $advanced = Contact::factory()->for($user)->create();
        $completed = Contact::factory()->for($user)->create();
        $current = Contact::factory()->for($user)->create();
        $sequence->contacts()->attach($completed, ['completed_at' => now()]);
        foreach ([$advanced, $completed, $current] as $contact) {
            ContactMail::factory()->for($contact)->forSequenceStep($step)->create();
        }
        ContactMail::factory()->for($advanced)->forSequenceStep($laterStep)->create();

        $parameters = ['sequence' => $sequence->id, 'sequenceStep' => $step->id];
        $this->actingAs($user);
        foreach ([[], ['current_only' => 0], ['current_only' => 1]] as $filters) {
            $response = $this->get(route('sequences.steps.contact-mails.export', [...$parameters, ...$filters]))->assertOk();
            $rows = $this->csvRows($response->streamedContent());
            $this->assertCount(4, $rows);
            $this->assertEqualsCanonicalizing(
                [$advanced->email, $completed->email, $current->email],
                array_column(array_slice($rows, 1), 0),
            );
        }
    }

    public function test_export_supports_search_dates_and_exact_status(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();
        foreach (['2026-08-10', '2026-07-10'] as $index => $sentAt) {
            $contact = Contact::factory()->for($user)->create(['email' => "recipient{$index}@example.com"]);
            ContactMail::factory()->for($contact)->forSequenceStep($step)->bounced()->create([
                'sent_at' => $sentAt, 'subject' => 'Welcome', 'failure_reason' => 'Invalid address',
            ]);
        }

        $response = $this->actingAs($user)->get(route('sequences.steps.contact-mails.export', [
            'sequence' => $sequence->id, 'sequenceStep' => $step->id,
            'search' => 'recipient', 'status' => 'bounced',
            'sent_after' => '2026-08-01', 'sent_before' => '2026-08-31',
        ]))->assertOk();
        $rows = $this->csvRows($response->streamedContent());
        $this->assertCount(2, $rows);
        $this->assertSame('recipient0@example.com', $rows[1][0]);
        $this->assertSame('Invalid address', $rows[1][16]);
    }

    public function test_history_export_includes_advanced_or_completed_contacts_with_matching_filters(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create(['delay_days' => 1]);
        $laterStep = SequenceStep::factory()->for($sequence)->create(['delay_days' => 2]);
        $advanced = Contact::factory()->for($user)->create();
        $completed = Contact::factory()->for($user)->create();
        $sequence->contacts()->attach($completed, ['completed_at' => now()]);

        ContactMail::factory()->for($advanced)->forSequenceStep($step)->clicked()->create();
        ContactMail::factory()->for($completed)->forSequenceStep($step)->clicked()->create();
        ContactMail::factory()->for($advanced)->forSequenceStep($laterStep)->clicked()->create();
        $pending = Contact::factory()->for($user)->create();
        ContactMail::factory()->for($pending)->forSequenceStep($step)->pending()->create();

        $response = $this->actingAs($user)->get(route('sequences.steps.contact-mails.export', [
            'sequence' => $sequence->id, 'sequenceStep' => $step->id,
            'filter' => 'clicked', 'sort' => 'email', 'direction' => 'asc',
        ]))->assertOk();

        $rows = $this->csvRows($response->streamedContent());
        $this->assertCount(3, $rows);
        $expectedEmails = [$advanced->email, $completed->email];
        sort($expectedEmails);
        $this->assertSame($expectedEmails, array_column(array_slice($rows, 1), 0));
    }

    public function test_csv_escapes_quotes_newlines_and_spreadsheet_formulas(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();
        $contact = Contact::factory()->for($user)->create(['name' => '=1+1']);
        ContactMail::factory()->for($contact)->forSequenceStep($step)->create(['subject' => "Hello, \"reader\"\nWelcome"]);

        $response = $this->actingAs($user)->get(route('sequences.steps.contact-mails.export', [
            'sequence' => $sequence->id, 'sequenceStep' => $step->id,
        ]))->assertOk();
        $rows = $this->csvRows($response->streamedContent());
        $this->assertSame("'=1+1", $rows[1][1]);
        $this->assertSame("Hello, \"reader\"\nWelcome", $rows[1][4]);
    }

    public function test_export_rejects_unknown_foreign_or_mismatched_steps(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $foreign = SequenceStep::factory()->create();
        $mismatched = SequenceStep::factory()->for(Sequence::factory()->for($user))->create();
        $this->actingAs($user);

        foreach ([999999, $foreign->id, $mismatched->id] as $stepId) {
            $this->get(route('sequences.steps.contact-mails.export', [
                'sequence' => $sequence->id, 'sequenceStep' => $stepId,
            ]))->assertNotFound();
        }
    }

    public function test_empty_export_contains_only_the_header_and_invalid_filters_are_rejected(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();
        $parameters = ['sequence' => $sequence->id, 'sequenceStep' => $step->id];

        $response = $this->actingAs($user)->get(route('sequences.steps.contact-mails.export', $parameters))->assertOk();
        $this->assertCount(1, $this->csvRows($response->streamedContent()));
        $this->getJson(route('sequences.steps.contact-mails.export', [...$parameters, 'sort' => 'password']))
            ->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson(route('sequences.steps.contact-mails.export', [...$parameters, 'current_only' => 'invalid']))
            ->assertUnprocessable()->assertJsonValidationErrors('current_only');
    }

    public function test_api_export_requires_authentication_and_accepts_the_mcp_bearer_token(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();
        $url = route('api.v1.sequences.steps.contact-mails.export', [
            'sequenceId' => $sequence->id, 'sequenceStepId' => $step->id,
        ]);
        $this->getJson($url)->assertUnauthorized();
        $token = $user->createToken('mcp')->plainTextToken;
        $this->withToken($token)->get($url)->assertOk()->assertDownload();

        $otherUser = User::factory()->create();
        $otherToken = $otherUser->createToken('mcp')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($otherToken)->get($url)->assertNotFound();
    }

    /** @return list<list<string|null>> */
    private function csvRows(string $csv): array
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
}
