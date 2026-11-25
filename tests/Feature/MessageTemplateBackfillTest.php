<?php

namespace Tests\Feature;

use App\Models\EmailTemplate;
use App\Models\Message;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MessageTemplateBackfillTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Replays the production backfill logic on a fresh database to verify
     * it correctly wraps existing messages into their client's default template
     * without the RefreshDatabase trait hiding pre-migration state.
     */
    public function test_backfill_wraps_messages_into_default_template(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $defaultTemplate = EmailTemplate::factory()
            ->for($user)
            ->default()
            ->create([
                'html_content' => '<header>H</header><p>{{ content }}</p><footer><a href="{{ unsubscribe_url }}">u</a></footer>',
            ]);

        $newsletter = $user->newsletters()->create([
            'title' => 'Legacy',
            'status' => 'draft',
            'filters' => [],
        ]);

        $message = $newsletter->message()->create([
            'user_id' => $user->id,
            'subject' => 'Legacy subject',
            'markdown_content' => '',
            'html_content' => '<p>Legacy body</p>',
            'text_content' => 'Legacy body',
        ]);

        // Force message into pre-backfill state (clear template fields even if
        // the production backfill migration already ran in the test suite).
        DB::table('messages')->where('id', $message->id)->update([
            'email_template_id' => null,
            'template_slot_content' => null,
            'html_content' => '<p>Legacy body</p>',
        ]);

        $this->runBackfill();

        $message->refresh();

        $this->assertSame($defaultTemplate->id, $message->email_template_id);
        $this->assertSame('<p>Legacy body</p>', $message->template_slot_content);
        $this->assertStringContainsString('<p>Legacy body</p>', $message->html_content);
        $this->assertStringContainsString('<header>H</header>', $message->html_content);
        $this->assertStringNotContainsString('{{ content }}', $message->html_content);
    }

    public function test_backfill_is_idempotent(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $defaultTemplate = EmailTemplate::factory()->for($user)->default()->create([
            'html_content' => '<p>{{ content }}</p><a href="{{ unsubscribe_url }}">u</a>',
        ]);

        $newsletter = $user->newsletters()->create(['title' => 't', 'status' => 'draft', 'filters' => []]);
        $message = $newsletter->message()->create([
            'user_id' => $user->id,
            'subject' => 's',
            'markdown_content' => '',
            'html_content' => '<p>Body</p>',
            'text_content' => 'Body',
        ]);

        DB::table('messages')->where('id', $message->id)->update([
            'email_template_id' => null,
            'template_slot_content' => null,
            'html_content' => '<p>Body</p>',
        ]);

        $this->runBackfill();
        $snapshotHtml = Message::find($message->id)->html_content;

        // Run again — should no-op since email_template_id now set.
        $this->runBackfill();

        $this->assertSame($snapshotHtml, Message::find($message->id)->html_content);
    }

    public function test_backfill_skips_opt_in_messages(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $defaultTemplate = EmailTemplate::factory()->for($user)->default()->create([
            'html_content' => '<p>{{ content }}</p><a href="{{ unsubscribe_url }}">u</a>',
        ]);

        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();
        $stepMessage = $step->message()->create([
            'user_id' => $user->id,
            'subject' => 'step',
            'markdown_content' => '',
            'html_content' => '<p>Step body</p>',
            'text_content' => 'Step body',
        ]);

        DB::table('messages')->where('id', $stepMessage->id)->update([
            'email_template_id' => null,
            'template_slot_content' => null,
            'html_content' => '<p>Step body</p>',
        ]);

        $this->runBackfill();

        $stepMessage->refresh();
        $this->assertSame($defaultTemplate->id, $stepMessage->email_template_id);
    }

    private function runBackfill(): void
    {
        require_once database_path('migrations/2026_04_23_100003_backfill_messages_with_default_template.php');

        $migrationClass = new class extends Migration
        {
            public function up(): void {}

            public function down(): void {}
        };

        // Dynamically include + instantiate the anonymous migration class.
        $migration = require database_path('migrations/2026_04_23_100003_backfill_messages_with_default_template.php');
        $migration->up();
    }
}
