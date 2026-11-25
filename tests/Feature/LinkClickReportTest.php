<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\ContactMailClick;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\LinkClickReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkClickReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_groups_named_links_by_name_and_other_links_by_url_without_utm(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create();
        $ana = $this->mailFor($user, $newsletter);
        $ben = $this->mailFor($user, $newsletter);
        $this->mailFor($user, $newsletter, delivered: false);
        $this->mailFor($user, $newsletter);

        $this->click($ana, 'https://example.com/sale?utm_source=a', 'black-friday');
        $this->click($ana, 'https://example.com/sale?utm_source=a', 'black-friday');
        $this->click($ben, 'https://example.com/sale-v2?utm_source=b', 'black-friday');
        $this->click($ana, 'https://Example.com/pricing?plan=pro&utm_source=a#top');
        $this->click($ben, 'https://example.com/pricing?utm_medium=mail&plan=pro');

        $report = app(LinkClickReport::class)->summary($newsletter);

        $this->assertSame(3, $report['delivered']);
        $this->assertSame([
            [
                'key' => 'name:black-friday',
                'name' => 'black-friday',
                'url' => 'https://example.com/sale',
                'unique_contacts' => 2,
                'total_clicks' => 3,
                'click_rate' => 66.67,
            ],
            [
                'key' => 'url:https://example.com/pricing?plan=pro',
                'name' => null,
                'url' => 'https://example.com/pricing?plan=pro',
                'unique_contacts' => 2,
                'total_clicks' => 2,
                'click_rate' => 66.67,
            ],
        ], $report['links']);
    }

    public function test_summary_excludes_clicks_of_another_client_and_of_another_newsletter(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $otherNewsletter = Newsletter::factory()->for($user)->create();
        $this->click($this->mailFor($user, $otherNewsletter), 'https://example.com/other');

        $foreignMail = $this->mailFor(User::factory()->create(), $newsletter);
        $this->click($foreignMail, 'https://example.com/foreign');

        $this->actingAsTenant($user->client_id);
        $report = app(LinkClickReport::class)->summary($newsletter);

        $this->assertSame([], $report['links']);
        $this->assertSame(0, $report['delivered']);
    }

    public function test_summary_has_no_click_rate_when_nothing_is_delivered(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create();
        $this->click($this->mailFor($user, $newsletter, delivered: false), 'https://example.com/sale');

        $report = app(LinkClickReport::class)->summary($newsletter);

        $this->assertNull($report['links'][0]['click_rate']);
    }

    public function test_filter_contact_mails_keeps_the_contacts_that_clicked_the_link(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create();
        $ana = $this->mailFor($user, $newsletter);
        $ben = $this->mailFor($user, $newsletter);
        $cleo = $this->mailFor($user, $newsletter);
        $this->click($ana, 'https://example.com/pricing?utm_source=a');
        $this->click($ana, 'https://example.com/pricing?utm_source=b');
        $this->click($ben, 'https://example.com/sale', 'black-friday');
        $this->click($cleo, 'https://example.com/pricing', 'pricing-button');

        $report = app(LinkClickReport::class);

        $byUrl = $report->filterContactMails($newsletter->contactMails()->getQuery(), $newsletter, 'url:https://example.com/pricing')->get();
        $this->assertSame([$ana->id], $byUrl->pluck('id')->all());
        $this->assertSame(2, $byUrl->first()->link_clicks_count);
        $this->assertNotNull($byUrl->first()->link_first_clicked_at);

        $byName = $report->filterContactMails($newsletter->contactMails()->getQuery(), $newsletter, 'name:black-friday')->get();
        $this->assertSame([$ben->id], $byName->pluck('id')->all());
    }

    public function test_is_valid_key_accepts_only_name_and_url_keys(): void
    {
        $this->assertTrue(LinkClickReport::isValidKey('name:sale'));
        $this->assertTrue(LinkClickReport::isValidKey('url:https://example.com'));
        $this->assertFalse(LinkClickReport::isValidKey('url:'));
        $this->assertFalse(LinkClickReport::isValidKey('name:'));
        $this->assertFalse(LinkClickReport::isValidKey('sale'));
        $this->assertFalse(LinkClickReport::isValidKey(null));
    }

    private function mailFor(User $user, Newsletter $newsletter, bool $delivered = true): ContactMail
    {
        return ContactMail::factory()
            ->for(Contact::factory()->for($user))
            ->for($newsletter, 'mailable')
            ->create(['delivered_at' => $delivered ? now() : null]);
    }

    private function click(ContactMail $mail, string $link, ?string $linkName = null): void
    {
        ContactMailClick::factory()->create([
            'contact_mail_id' => $mail->id,
            'contact_id' => $mail->contact_id,
            'link' => $link,
            'link_name' => $linkName,
        ]);
    }
}
