<?php

namespace Tests\Feature;

use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\CreateNewsletterTool;
use App\Mcp\Tools\UpdateSequenceStepTool;
use App\Models\EmailIdentity;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Each place that saves email content refuses content that is not valid
 * Liquid, with a message that names the error. Plain `{{ name }}` content
 * saves the same as before Liquid.
 */
class LiquidSaveValidationTest extends TestCase
{
    use RefreshDatabase;

    private const BROKEN = '<p>{% if vip %}VIP</p>';

    public function test_the_api_refuses_a_newsletter_body_that_is_not_valid_liquid(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->postJson('/api/v1/newsletters', ['title' => 'News', 'subject' => 'Hi', 'html_content' => self::BROKEN, 'status' => 'draft'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.html_content.0', fn (string $message): bool => str_starts_with($message, 'The Liquid code has an error'));
    }

    public function test_the_api_saves_valid_liquid_and_plain_placeholders(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->postJson('/api/v1/newsletters', [
            'title' => 'News',
            'subject' => 'Your {{ 1st_order }}',
            'html_content' => '<p>{% if vip %}VIP{% endif %} {{ name | default: "friend" }}</p>',
            'status' => 'draft',
        ])->assertCreated();
    }

    public function test_the_api_refuses_a_subject_with_an_unknown_filter(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->postJson('/api/v1/newsletters', ['title' => 'News', 'subject' => 'Hi {{ name | shout }}', 'status' => 'draft'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.subject.0', fn (string $message): bool => str_contains($message, 'shout'));
    }

    public function test_the_dashboard_refuses_newsletter_content_that_is_not_valid_liquid(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();

        $this->actingAs($user)
            ->patch(route('newsletters.content.update', $newsletter), ['title' => 'News', 'subject' => 'Hi', 'html_content' => self::BROKEN])
            ->assertSessionHasErrors(['html_content' => 'The Liquid code has an error on line 1: \'if\' tag was never closed']);
    }

    public function test_the_dashboard_refuses_a_sequence_step_that_is_not_valid_liquid(): void
    {
        $user = User::factory()->create();
        $step = SequenceStep::factory()->for(Sequence::factory()->for($user))->create();

        $this->actingAs($user)
            ->patch(route('sequences.steps.update', ['sequence' => $step->sequence_id, 'sequenceStep' => $step]), [
                'delay_days' => 1,
                'delay_type' => 'days',
                'subject' => '{% unless %}',
                'content' => '<p>Hi</p>',
                'email_identity_id' => EmailIdentity::factory()->for($user)->create()->id,
            ])
            ->assertSessionHasErrors('subject');
    }

    public function test_the_dashboard_refuses_an_opt_in_email_and_a_block_that_are_not_valid_liquid(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();

        $this->actingAs($user)
            ->put(route('lists.update', $list), [
                'name' => 'Leads',
                'opt_in_enabled' => true,
                'opt_in_subject' => 'Confirm',
                'opt_in_html_content' => self::BROKEN.'<a href="{{ opt_in_url }}">Confirm</a>',
                'opt_in_email_identity_id' => EmailIdentity::factory()->for($user)->create()->id,
            ])
            ->assertSessionHasErrors('opt_in_html_content');

        $this->actingAs($user)
            ->post(route('blocks.store'), ['name' => 'footer', 'html_content' => self::BROKEN])
            ->assertSessionHasErrors('html_content');
    }

    public function test_the_mcp_tools_refuse_content_that_is_not_valid_liquid(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $step = SequenceStep::factory()->for(Sequence::factory()->for($user))->create();

        ByoMailerServer::actingAs($user)->tool(CreateNewsletterTool::class, [
            'title' => 'News',
            'html_content' => self::BROKEN,
            'email_identity_id' => $identity->id,
        ])->assertHasErrors(['The Liquid code has an error']);

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceStepTool::class, [
            'sequence_step_id' => $step->id,
            'delay_type' => 'hours',
            'delay_days' => 6,
            'email_identity_id' => $identity->id,
            'subject' => 'Hi {{ name | shout }}',
        ])->assertHasErrors(['shout']);

        $this->assertDatabaseMissing('newsletters', ['title' => 'News']);
    }
}
