<?php

namespace Tests\Feature;

use App\Enums\SequenceStatus;
use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\UpdateAutomationTool;
use App\Mcp\Tools\UpdateSequenceStepTool;
use App\Mcp\Tools\UpdateSequenceTool;
use App\Models\Automation;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\Tag;
use App\Models\User;
use App\Services\AutomationStatusService;
use App\Services\SequenceStatusService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * A status check and the write that depends on it must see the same row.
 * Each test changes the row after the caller read it and before the write.
 */
class GuardedStatusWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_step_does_not_go_live_when_its_content_became_blank_after_the_read(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create(['status' => 'draft']);
        $step->load('message');

        DB::table('messages')->where('id', $step->message->id)->update(['subject' => '']);

        try {
            app(SequenceStatusService::class)->setStepStatus($user, $step, SequenceStatus::Live);
            $this->fail('The step went live with a blank subject.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Add a subject before you send.', $exception->getMessage());
        }

        $this->assertSame('draft', DB::table('sequence_steps')->where('id', $step->id)->value('status'));
    }

    public function test_an_automation_does_not_go_live_when_a_draft_sequence_was_added_after_the_read(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $tag = Tag::factory()->for($user)->create();
        $draft = Sequence::factory()->for($user)->create(['status' => 'draft']);
        $automation = Automation::factory()->for($user)->create([
            'is_live' => false,
            'condition' => ['type' => 'tag_added', 'value' => $tag->id],
            'actions' => [['type' => 'add_tag', 'value' => $tag->id]],
        ]);

        DB::table('automations')->where('id', $automation->id)->update([
            'actions' => json_encode([['type' => 'add_sequence', 'value' => $draft->id]]),
        ]);

        $this->expectException(ValidationException::class);

        try {
            app(AutomationStatusService::class)->setLive($automation, true);
        } finally {
            $this->assertSame(0, (int) DB::table('automations')->where('id', $automation->id)->value('is_live'));
        }
    }

    public function test_mcp_sequence_update_saves_nothing_when_the_sequence_went_live_after_the_check(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'draft', 'name' => 'Old']);
        $this->goLiveAfterTheFirstRead(Sequence::class, 'sequences', 'status', 'live');

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceTool::class, [
            'sequence_id' => $sequence->id,
            'name' => 'New',
        ])->assertHasErrors(['changed while this tool ran']);

        $this->assertSame('Old', DB::table('sequences')->where('id', $sequence->id)->value('name'));
    }

    public function test_mcp_step_update_saves_nothing_when_the_step_went_live_after_the_check(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create(['status' => 'draft', 'delay_days' => 2]);
        $this->goLiveAfterTheFirstRead(SequenceStep::class, 'sequence_steps', 'status', 'live');

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceStepTool::class, [
            'sequence_step_id' => $step->id,
            'delay_type' => 'days',
            'delay_days' => 9,
        ])->assertHasErrors(['changed while this tool ran']);

        $this->assertSame(2, (int) DB::table('sequence_steps')->where('id', $step->id)->value('delay_days'));
    }

    public function test_mcp_automation_update_saves_nothing_when_the_automation_went_live_after_the_check(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create();
        $draft = Sequence::factory()->for($user)->create(['status' => 'draft']);
        $automation = Automation::factory()->for($user)->create([
            'is_live' => false,
            'condition' => ['type' => 'tag_added', 'value' => $tag->id],
            'actions' => [['type' => 'add_tag', 'value' => $tag->id]],
        ]);
        $this->goLiveAfterTheFirstRead(Automation::class, 'automations', 'is_live', true);

        ByoMailerServer::actingAs($user)->tool(UpdateAutomationTool::class, [
            'automation_id' => $automation->id,
            'actions' => [['type' => 'add_sequence', 'value' => $draft->id]],
        ])->assertHasErrors(['changed while this tool ran']);

        $this->assertStringNotContainsString('add_sequence', (string) DB::table('automations')->where('id', $automation->id)->value('actions'));
    }

    public function test_web_automation_update_refuses_a_draft_sequence_when_the_automation_went_live_after_the_validation(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create();
        $draft = Sequence::factory()->for($user)->create(['status' => 'draft']);
        $automation = Automation::factory()->for($user)->create([
            'is_live' => false,
            'condition' => ['type' => 'tag_added', 'value' => $tag->id],
            'actions' => [['type' => 'add_tag', 'value' => $tag->id]],
        ]);
        $this->goLiveAfterTheFirstRead(Automation::class, 'automations', 'is_live', true);

        $this->actingAs($user)->put(route('automations.update', $automation), [
            'name' => 'Renamed',
            'type' => 'simple',
            'condition' => ['type' => 'tag_added', 'value' => $tag->id],
            'actions' => [['type' => 'add_sequence', 'value' => $draft->id]],
        ])->assertSessionHasErrors('actions.0.value');

        $this->assertStringNotContainsString('add_sequence', (string) DB::table('automations')->where('id', $automation->id)->value('actions'));
    }

    /**
     * Another request changes the row right after the first read of it.
     *
     * @param  class-string<Model>  $model
     */
    private function goLiveAfterTheFirstRead(string $model, string $table, string $column, mixed $value): void
    {
        $fired = false;

        $model::retrieved(function (Model $record) use (&$fired, $table, $column, $value): void {
            if ($fired) {
                return;
            }

            $fired = true;
            DB::table($table)->where('id', $record->getKey())->update([$column => $value]);
        });
    }
}
