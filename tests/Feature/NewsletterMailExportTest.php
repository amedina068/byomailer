<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\Message;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\NewsletterMailExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class NewsletterMailExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_requires_authentication(): void
    {
        $newsletter = Newsletter::factory()->create();

        $this->get(route('newsletters.export', $newsletter->id))->assertRedirect(route('login'));
    }

    public function test_export_streams_status_recipient_and_personalized_body(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message->forceFill([
            'subject' => 'Hello {{ name }}',
            'text_content' => "Hi {{ name }},\nLeave: {{ unsubscribe_url }}",
        ])->save();
        $contact = Contact::factory()->for($user)->create(['email' => 'alice@example.com', 'name' => 'Alice']);
        $mail = ContactMail::factory()->forNewsletter($newsletter)->for($contact)->create([
            'message_id' => $newsletter->message->id,
            'subject' => 'Hello {{ name }}',
            'template_data' => ['name' => 'Alice'],
            'status' => 'delivered',
            'delivered_at' => '2026-09-01 10:00:00',
        ]);

        $response = $this->actingAs($user)->get(route('newsletters.export', $newsletter->id));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertDownload("newsletter-{$newsletter->id}-mails.csv");

        $rows = $this->csvRows($response);
        $this->assertSame(NewsletterMailExport::columns(), array_keys($rows[0]));
        $this->assertCount(1, $rows);
        $this->assertSame('alice@example.com', $rows[0]['email']);
        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertSame('delivered', $rows[0]['status']);
        $this->assertSame('Hello Alice', $rows[0]['subject']);
        $this->assertSame('2026-09-01 10:00:00', $rows[0]['delivered_at']);
        $this->assertStringContainsString('Hi Alice,', $rows[0]['body']);
        $this->assertStringContainsString('/unsubscribe/'.$mail->uuid, $rows[0]['body']);
        $this->assertStringNotContainsString('{{', $rows[0]['body']);
    }

    public function test_export_derives_body_from_html_and_expands_blocks_when_text_is_missing(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        Block::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'footer',
            'html_content' => '<p>Sent by Acme</p>',
        ]);
        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message->forceFill([
            'html_content' => '<p>Welcome {{ name }}</p>{{ block.footer }}',
            'text_content' => null,
        ])->save();
        ContactMail::factory()->forNewsletter($newsletter)->create([
            'contact_id' => Contact::factory()->for($user),
            'message_id' => $newsletter->message->id,
            'template_data' => ['name' => 'Bob'],
        ]);

        $rows = $this->csvRows($this->actingAs($user)->get(route('newsletters.export', $newsletter->id)));

        $this->assertStringContainsString('Welcome Bob', $rows[0]['body']);
        $this->assertStringContainsString('Sent by Acme', $rows[0]['body']);
        $this->assertStringNotContainsString('<p>', $rows[0]['body']);
    }

    public function test_export_filters_by_status(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        ContactMail::factory()->forNewsletter($newsletter)->create([
            'contact_id' => Contact::factory()->for($user)->state(['email' => 'bounced@example.com']),
            'status' => 'bounced',
        ]);
        ContactMail::factory()->forNewsletter($newsletter)->create([
            'contact_id' => Contact::factory()->for($user)->state(['email' => 'ok@example.com']),
            'status' => 'delivered',
        ]);

        $rows = $this->csvRows($this->actingAs($user)->get(route('newsletters.export', [$newsletter->id, 'status' => 'bounced'])));

        $this->assertSame(['bounced@example.com'], array_column($rows, 'email'));
    }

    public function test_export_only_includes_mails_of_the_newsletter(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $otherNewsletter = Newsletter::factory()->for($user)->create();
        ContactMail::factory()->forNewsletter($newsletter)->create([
            'contact_id' => Contact::factory()->for($user)->state(['email' => 'mine@example.com']),
        ]);
        ContactMail::factory()->forNewsletter($otherNewsletter)->create([
            'contact_id' => Contact::factory()->for($user)->state(['email' => 'other@example.com']),
        ]);

        $rows = $this->csvRows($this->actingAs($user)->get(route('newsletters.export', $newsletter->id)));

        $this->assertSame(['mine@example.com'], array_column($rows, 'email'));
    }

    public function test_export_of_another_clients_newsletter_returns_not_found(): void
    {
        $owner = User::factory()->create();
        $newsletter = Newsletter::factory()->for($owner)->create();
        ContactMail::factory()->forNewsletter($newsletter)->create([
            'contact_id' => Contact::factory()->for($owner),
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('newsletters.export', $newsletter->id))
            ->assertNotFound();
    }

    public function test_export_marks_the_ab_variant_of_each_mail(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->withAbTest()->create();
        $messageB = Message::query()->where('messageable_id', $newsletter->id)->where('variant', 'b')->firstOrFail();
        ContactMail::factory()->forNewsletter($newsletter)->create([
            'contact_id' => Contact::factory()->for($user),
            'message_id' => $messageB->id,
        ]);

        $rows = $this->csvRows($this->actingAs($user)->get(route('newsletters.export', $newsletter->id)));

        $this->assertSame('B', $rows[0]['variant']);
    }

    public function test_export_escapes_values_that_a_spreadsheet_reads_as_formulas(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        ContactMail::factory()->forNewsletter($newsletter)->create([
            'contact_id' => Contact::factory()->for($user)->state(['name' => '=HYPERLINK("http://evil.test")']),
        ]);

        $rows = $this->csvRows($this->actingAs($user)->get(route('newsletters.export', $newsletter->id)));

        $this->assertSame('\'=HYPERLINK("http://evil.test")', $rows[0]['name']);
    }

    public function test_export_ignores_nested_template_data_values(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message->forceFill(['text_content' => 'Hi {{ name }}'])->save();
        ContactMail::factory()->forNewsletter($newsletter)->create([
            'contact_id' => Contact::factory()->for($user),
            'message_id' => $newsletter->message->id,
            'template_data' => ['name' => 'Cleo', 'contact' => ['name' => 'Nested']],
        ]);

        $rows = $this->csvRows($this->actingAs($user)->get(route('newsletters.export', $newsletter->id)));

        $this->assertSame('Hi Cleo', $rows[0]['body']);
    }

    public function test_export_renders_the_liquid_code_of_the_body_and_the_subject(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message->forceFill([
            'subject' => 'Hi {{ name | upcase }}',
            'html_content' => '<p>{% for item in items %}[{{ item }}]{% endfor %}</p>',
            'text_content' => null,
        ])->save();
        ContactMail::factory()->forNewsletter($newsletter)->create([
            'contact_id' => Contact::factory()->for($user),
            'message_id' => $newsletter->message->id,
            'subject' => 'Hi {{ name | upcase }}',
            'template_data' => ['name' => 'Cleo', 'items' => ['a', 'b']],
        ]);

        $rows = $this->csvRows($this->actingAs($user)->get(route('newsletters.export', $newsletter->id)));

        $this->assertSame('Hi CLEO', $rows[0]['subject']);
        $this->assertSame('[a][b]', $rows[0]['body']);
    }

    public function test_a_liquid_render_error_does_not_stop_the_export(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message->forceFill(['text_content' => '{{ 10 | divided_by: count }}'])->save();
        ContactMail::factory()->forNewsletter($newsletter)->create([
            'contact_id' => Contact::factory()->for($user),
            'message_id' => $newsletter->message->id,
            'template_data' => ['count' => '0'],
        ]);

        $rows = $this->csvRows($this->actingAs($user)->get(route('newsletters.export', $newsletter->id)));

        $this->assertSame('{{ 10 | divided_by: count }}', $rows[0]['body']);
    }

    public function test_export_rejects_a_status_longer_than_fifty_characters(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('newsletters.export', [$newsletter->id, 'status' => str_repeat('a', 51)]))
            ->assertSessionHasErrors(['status' => 'The status field must not be greater than 50 characters.']);
    }

    /**
     * @return list<array<string, string>>
     */
    private function csvRows(TestResponse $response): array
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $response->streamedContent());
        rewind($stream);

        $header = fgetcsv($stream);
        $rows = [];

        while (($row = fgetcsv($stream)) !== false) {
            $rows[] = array_combine($header, $row);
        }

        fclose($stream);

        return $rows;
    }
}
