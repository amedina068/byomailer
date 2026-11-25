<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\CalculateNewsletterContactsTool;
use App\Mcp\Tools\CreateNewsletterTool;
use App\Mcp\Tools\CreateSequenceStepTool;
use App\Mcp\Tools\CreateSequenceTool;
use App\Mcp\Tools\ListContactsTool;
use App\Mcp\Tools\UpdateNewsletterTool;
use App\Mcp\Tools\UpdateSequenceStepTool;
use App\Mcp\Tools\UpdateSequenceTool;
use App\Models\ContactAttribute;
use App\Models\EmailIdentity;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\Tag;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilterValidationToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_newsletter_rejects_a_list_of_another_client(): void
    {
        $user = User::factory()->create();
        $foreignList = MailList::factory()->create();

        ByoMailerServer::actingAs($user)->tool(CreateNewsletterTool::class, [
            'title' => 'Foreign list',
            'filters' => [['category' => 'lists', 'mode' => 'any', 'values' => [$foreignList->id]]],
        ])->assertHasErrors(["The list with ID {$foreignList->id} does not exist."]);

        $this->assertSame(0, Newsletter::query()->count());
    }

    public function test_create_newsletter_rejects_a_tag_filter_without_a_mode(): void
    {
        $user = User::factory()->create();
        Tag::factory()->for($user)->create(['name' => 'vip']);

        ByoMailerServer::actingAs($user)->tool(CreateNewsletterTool::class, [
            'title' => 'No mode',
            'filters' => [['category' => 'tags', 'values' => ['vip']]],
        ])->assertHasErrors(['A match mode is required for this filter.']);

        $this->assertSame(0, Newsletter::query()->count());
    }

    public function test_update_newsletter_rejects_an_unknown_tag_and_keeps_the_old_filters(): void
    {
        $user = User::factory()->create();
        $filters = [['connector' => 'and', 'category' => 'tags', 'mode' => 'any', 'values' => ['vip']]];
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'draft', 'filters' => $filters]);

        ByoMailerServer::actingAs($user)->tool(UpdateNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'filters' => [['category' => 'tags', 'mode' => 'any', 'values' => ['ghost']]],
        ])->assertHasErrors(['The tag "ghost" does not exist.']);

        $this->assertSame($filters, $newsletter->refresh()->filters);
    }

    public function test_create_sequence_rejects_a_topic_of_another_client(): void
    {
        $user = User::factory()->create();
        $foreignTopic = Topic::factory()->create();

        ByoMailerServer::actingAs($user)->tool(CreateSequenceTool::class, [
            'name' => 'Foreign topic',
            'filters' => [['category' => 'topics', 'mode' => 'any', 'values' => [$foreignTopic->id]]],
        ])->assertHasErrors(["The topic with ID {$foreignTopic->id} does not exist."]);

        $this->assertSame(0, Sequence::query()->count());
    }

    public function test_update_sequence_rejects_an_engagement_filter_on_a_newsletter_of_another_client(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'draft', 'filters' => []]);
        $foreignNewsletter = Newsletter::factory()->create();

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceTool::class, [
            'sequence_id' => $sequence->id,
            'filters' => [[
                'category' => 'engagement',
                'operator' => 'opened',
                'values' => ['newsletter_id' => $foreignNewsletter->id, 'within' => 'ever'],
            ]],
        ])->assertHasErrors(['The selected newsletter does not exist.']);

        $this->assertSame([], $sequence->refresh()->filters);
    }

    public function test_create_sequence_step_rejects_a_list_of_another_client(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $foreignList = MailList::factory()->create();

        ByoMailerServer::actingAs($user)->tool(CreateSequenceStepTool::class, [
            'sequence_id' => $sequence->id,
            'delay_type' => 'days',
            'delay_days' => 2,
            'email_identity_id' => $identity->id,
            'filters' => [['category' => 'lists', 'mode' => 'any', 'values' => [$foreignList->id]]],
        ])->assertHasErrors(["The list with ID {$foreignList->id} does not exist."]);

        $this->assertSame(0, SequenceStep::query()->count());
    }

    public function test_update_sequence_step_rejects_a_date_operator_that_does_not_exist(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create(['status' => 'draft', 'filters' => null]);

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceStepTool::class, [
            'sequence_step_id' => $step->id,
            'filters' => [['category' => 'date', 'operator' => 'sometime', 'values' => ['days' => 3]]],
        ])->assertHasErrors(['The selected operator is not valid for a date filter.']);

        $this->assertNull($step->refresh()->filters);
    }

    public function test_calculate_contacts_rejects_an_unknown_tag_instead_of_counting_every_contact(): void
    {
        $user = User::factory()->create();

        ByoMailerServer::actingAs($user)->tool(CalculateNewsletterContactsTool::class, [
            'filters' => [['category' => 'tags', 'mode' => 'any', 'values' => ['ghost']]],
        ])->assertHasErrors(['The tag "ghost" does not exist.']);
    }

    public function test_list_contacts_rejects_a_list_of_another_client(): void
    {
        $user = User::factory()->create();
        $foreignList = MailList::factory()->create();

        ByoMailerServer::actingAs($user)->tool(ListContactsTool::class, [
            'filters' => [['category' => 'lists', 'mode' => 'any', 'values' => [$foreignList->id]]],
        ])->assertHasErrors(["The list with ID {$foreignList->id} does not exist."]);
    }

    public function test_create_sequence_rejects_a_date_filter_with_an_invalid_date(): void
    {
        $user = User::factory()->create();

        ByoMailerServer::actingAs($user)->tool(CreateSequenceTool::class, [
            'name' => 'Bad date',
            'filters' => [['category' => 'date', 'operator' => 'before', 'values' => ['date' => '2026-02-31']]],
        ])->assertHasErrors(['Enter a valid date in the YYYY-MM-DD format.']);

        $this->assertSame(0, Sequence::query()->count());
    }

    public function test_create_sequence_rejects_an_engagement_window_without_days(): void
    {
        $user = User::factory()->create();

        ByoMailerServer::actingAs($user)->tool(CreateSequenceTool::class, [
            'name' => 'No days',
            'filters' => [['category' => 'engagement', 'operator' => 'opened', 'values' => ['within' => 'last_n_days']]],
        ])->assertHasErrors(['Enter a number of days from 1 to 3650.']);

        $this->assertSame(0, Sequence::query()->count());
    }

    public function test_create_sequence_rejects_a_location_filter_with_blank_values(): void
    {
        $user = User::factory()->create();

        ByoMailerServer::actingAs($user)->tool(CreateSequenceTool::class, [
            'name' => 'Blank country',
            'filters' => [['category' => 'location', 'field' => 'country', 'mode' => 'any', 'values' => ['  ']]],
        ])->assertHasErrors(['Choose at least one location.']);

        $this->assertSame(0, Sequence::query()->count());
    }

    public function test_create_sequence_rejects_an_attribute_comparison_without_a_number(): void
    {
        $user = User::factory()->create();
        ContactAttribute::factory()->for($user)->create(['name' => 'age']);

        ByoMailerServer::actingAs($user)->tool(CreateSequenceTool::class, [
            'name' => 'Not a number',
            'filters' => [['category' => 'attributes', 'mode' => 'all', 'values' => [['key' => 'age', 'operator' => 'gt', 'value' => 'old']]]],
        ])->assertHasErrors(['Attribute "age" needs a number for this operator.']);

        $this->assertSame(0, Sequence::query()->count());
    }

    public function test_create_sequence_saves_a_valid_filter_of_each_category(): void
    {
        $user = User::factory()->create();
        Tag::factory()->for($user)->create(['name' => 'vip']);
        $list = MailList::factory()->for($user)->create();
        $topic = Topic::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        ContactAttribute::factory()->for($user)->create(['name' => 'age']);

        $filters = [
            ['connector' => 'and', 'category' => 'tags', 'mode' => 'any', 'values' => ['vip']],
            ['connector' => 'and', 'category' => 'lists', 'mode' => 'all', 'values' => [$list->id]],
            ['connector' => 'and', 'category' => 'topics', 'mode' => 'none', 'values' => [$topic->id]],
            ['connector' => 'and', 'category' => 'attributes', 'mode' => 'all', 'values' => [['key' => 'age', 'operator' => 'gt', 'value' => '30']]],
            ['connector' => 'and', 'category' => 'date', 'operator' => 'in_last', 'values' => ['days' => 30]],
            ['connector' => 'or', 'category' => 'engagement', 'operator' => 'opened', 'values' => ['newsletter_id' => $newsletter->id, 'within' => 'last_n_days', 'days' => 7]],
            ['connector' => 'and', 'category' => 'location', 'field' => 'country', 'mode' => 'any', 'values' => ['MX']],
        ];

        ByoMailerServer::actingAs($user)->tool(CreateSequenceTool::class, [
            'name' => 'Every category',
            'filters' => $filters,
        ])->assertOk();

        $this->assertCount(7, Sequence::query()->sole()->filters);
    }
}
