<?php

namespace Tests\Unit;

use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NewsletterMetricsScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_aggregates_all_states_correctly(): void
    {
        $newsletter = Newsletter::factory()->create();

        ContactMail::factory()->forNewsletter($newsletter)->pending()->create();
        ContactMail::factory()->forNewsletter($newsletter)->sent()->create();
        ContactMail::factory()->forNewsletter($newsletter)->delivered()->create();
        ContactMail::factory()->forNewsletter($newsletter)->opened()->create();
        ContactMail::factory()->forNewsletter($newsletter)->bounced()->create();
        ContactMail::factory()->forNewsletter($newsletter)->unsubscribed()->create();
        ContactMail::factory()->forNewsletter($newsletter)->complained()->create();

        $row = Newsletter::query()
            ->withoutGlobalScopes()
            ->withMetricsCounts()
            ->findOrFail($newsletter->id);

        $this->assertSame(7, (int) $row->contact_mails_count);
        // sent_count = anything with sent_at OR delivered_at — sent + delivered + opened + bounced + unsubscribed + complained = 6
        $this->assertSame(6, (int) $row->sent_count);
        // delivered_count = delivered + opened (opened state sets delivered_at) + unsubscribed + complained = 4
        $this->assertSame(4, (int) $row->delivered_count);
        $this->assertSame(1, (int) $row->pending_count);
        $this->assertSame(1, (int) $row->opened_count);
        $this->assertSame(1, (int) $row->bounced_count);
        $this->assertSame(1, (int) $row->unsubscribed_count);
        $this->assertSame(1, (int) $row->complained_count);
    }

    public function test_scope_returns_zero_counts_for_newsletter_with_no_mails(): void
    {
        $newsletter = Newsletter::factory()->create();

        $row = Newsletter::query()
            ->withoutGlobalScopes()
            ->withMetricsCounts()
            ->findOrFail($newsletter->id);

        $this->assertSame(0, (int) $row->contact_mails_count);
        $this->assertSame(0, (int) $row->sent_count);
        $this->assertSame(0, (int) $row->delivered_count);
        $this->assertSame(0, (int) $row->pending_count);
        $this->assertSame(0, (int) $row->opened_count);
        $this->assertSame(0, (int) $row->bounced_count);
        $this->assertSame(0, (int) $row->unsubscribed_count);
        $this->assertSame(0, (int) $row->complained_count);
    }

    public function test_scope_does_not_count_mails_from_other_newsletters(): void
    {
        $target = Newsletter::factory()->create();
        $other = Newsletter::factory()->create();

        ContactMail::factory()->forNewsletter($target)->sent()->create();
        ContactMail::factory()->forNewsletter($other)->sent()->count(5)->create();

        $row = Newsletter::query()
            ->withoutGlobalScopes()
            ->withMetricsCounts()
            ->findOrFail($target->id);

        $this->assertSame(1, (int) $row->contact_mails_count);
        $this->assertSame(1, (int) $row->sent_count);
    }

    public function test_scope_uses_a_single_join_not_correlated_subqueries(): void
    {
        Newsletter::factory()->count(3)->create();

        $sql = Newsletter::query()
            ->withoutGlobalScopes()
            ->withMetricsCounts()
            ->toSql();

        $this->assertStringContainsString('left join', strtolower($sql));
        $this->assertStringNotContainsString('select count(*) from '.$this->quoted('contact_mails'), strtolower($sql));
    }

    public function test_only_argument_restricts_aggregate_to_given_newsletter(): void
    {
        $target = Newsletter::factory()->create();
        $other = Newsletter::factory()->create();

        ContactMail::factory()->forNewsletter($target)->sent()->create();
        ContactMail::factory()->forNewsletter($other)->sent()->count(5)->create();

        $row = Newsletter::query()
            ->withoutGlobalScopes()
            ->withMetricsCounts($target->id)
            ->findOrFail($target->id);

        $this->assertSame(1, (int) $row->contact_mails_count);
        $this->assertSame(1, (int) $row->sent_count);

        $sql = strtolower(Newsletter::query()->withoutGlobalScopes()->withMetricsCounts($target->id)->toSql());
        $this->assertStringContainsString($this->quoted('mailable_id').' in', $sql);
    }

    public function test_scope_bounds_aggregate_to_authenticated_client(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $newsletter = Newsletter::factory()->create(['client_id' => $user->client_id]);

        Contact::factory()->for($user)->count(3)->create()->each(
            fn (Contact $contact) => ContactMail::factory()->for($contact)->forNewsletter($newsletter)->sent()->create()
        );

        $row = Newsletter::query()
            ->withMetricsCounts()
            ->findOrFail($newsletter->id);

        $this->assertSame(3, (int) $row->sent_count);

        $sql = strtolower(Newsletter::query()->withMetricsCounts()->toSql());
        $this->assertStringContainsString($this->quoted('client_id').' =', $sql);
    }

    public function test_scope_emits_one_query_regardless_of_newsletter_count(): void
    {
        Newsletter::factory()->count(5)->create()->each(function (Newsletter $n): void {
            ContactMail::factory()->forNewsletter($n)->sent()->count(3)->create();
        });

        DB::enableQueryLog();
        DB::flushQueryLog();

        Newsletter::query()
            ->withoutGlobalScopes()
            ->withMetricsCounts()
            ->get();

        $this->assertCount(1, DB::getQueryLog());
    }

    /**
     * The identifier as the SQL of the driver writes it: "name" on SQLite and
     * `name` on MySQL.
     */
    private function quoted(string $identifier): string
    {
        return DB::getQueryGrammar()->wrap($identifier);
    }
}
