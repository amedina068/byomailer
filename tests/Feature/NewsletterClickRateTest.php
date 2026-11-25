<?php

namespace Tests\Feature;

use App\Http\Resources\NewsletterResource;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class NewsletterClickRateTest extends TestCase
{
    use RefreshDatabase;

    public function test_metrics_include_clicked_count_and_click_rate(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $newsletter = Newsletter::factory()->for($user)->create();
        $contacts = Contact::factory()->count(4)->for($user)->create();

        ContactMail::factory()->clicked()->forNewsletter($newsletter)->create(['contact_id' => $contacts[0]->id]);
        ContactMail::factory()->clicked()->forNewsletter($newsletter)->create(['contact_id' => $contacts[1]->id]);
        ContactMail::factory()->sent()->forNewsletter($newsletter)->create(['contact_id' => $contacts[2]->id]);
        ContactMail::factory()->sent()->forNewsletter($newsletter)->create(['contact_id' => $contacts[3]->id]);

        $withMetrics = Newsletter::withMetricsCounts($newsletter->id)->findOrFail($newsletter->id);

        $metrics = (new NewsletterResource($withMetrics))->toArray(Request::create('/'))['metrics'];

        $this->assertSame(4, $metrics['sent']);
        $this->assertSame(2, $metrics['clicked']);
        $this->assertSame(50.0, $metrics['click_rate']);
    }

    public function test_click_rate_is_null_without_sends(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $newsletter = Newsletter::factory()->for($user)->create();

        $withMetrics = Newsletter::withMetricsCounts($newsletter->id)->findOrFail($newsletter->id);

        $metrics = (new NewsletterResource($withMetrics))->toArray(Request::create('/'))['metrics'];

        $this->assertSame(0, $metrics['clicked']);
        $this->assertNull($metrics['click_rate']);
    }
}
