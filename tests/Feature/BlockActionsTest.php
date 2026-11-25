<?php

namespace Tests\Feature;

use App\Actions\CreateBlock;
use App\Actions\DeleteBlock;
use App\Actions\UpdateBlock;
use App\Models\Block;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BlockActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_puts_the_block_in_the_client_of_the_user_and_records_the_author(): void
    {
        $user = User::factory()->create();

        $block = (new CreateBlock)->handle($user, 'footer', '<p>Bye</p>');

        $this->assertTrue($block->exists);
        $this->assertSame('footer', $block->name);
        $this->assertSame('<p>Bye</p>', $block->html_content);
        $this->assertSame($user->client_id, $block->client_id);
        $this->assertSame($user->id, $block->user_id);
    }

    public function test_update_replaces_the_name_and_the_html_content(): void
    {
        $user = User::factory()->create();
        $block = Block::factory()->create(['user_id' => $user->id, 'name' => 'old', 'html_content' => '<p>Old</p>']);

        $updated = (new UpdateBlock)->handle($block, 'new', '<p>New</p>');

        $this->assertSame('new', $updated->name);
        $this->assertSame('<p>New</p>', $block->fresh()->html_content);
    }

    public function test_delete_removes_the_block(): void
    {
        $user = User::factory()->create();
        $block = Block::factory()->create(['user_id' => $user->id]);

        (new DeleteBlock)->handle($block);

        $this->assertDatabaseMissing('blocks', ['id' => $block->id]);
    }

    public function test_web_writes_go_through_the_actions(): void
    {
        $user = User::factory()->create();
        $this->expectEachActionOnce();

        $this->actingAs($user)->post(route('blocks.store'), ['name' => 'web', 'html_content' => '<p>Web</p>'])->assertRedirect();
        $block = Block::query()->where('name', 'web')->sole();
        $this->actingAs($user)->put(route('blocks.update', $block->id), ['name' => 'web_2', 'html_content' => '<p>Web 2</p>'])->assertRedirect();
        $this->actingAs($user)->delete(route('blocks.destroy', $block->id))->assertRedirect();

        $this->assertDatabaseMissing('blocks', ['id' => $block->id]);
    }

    public function test_api_writes_go_through_the_actions(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $this->expectEachActionOnce();

        $blockId = $this->postJson('/api/v1/blocks', ['name' => 'api', 'html_content' => '<p>Api</p>'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/blocks/{$blockId}", ['name' => 'api_2', 'html_content' => '<p>Api 2</p>'])->assertOk()->assertJsonPath('data.name', 'api_2');
        $this->deleteJson("/api/v1/blocks/{$blockId}")->assertNoContent();

        $this->assertDatabaseMissing('blocks', ['id' => $blockId]);
    }

    public function test_both_channels_reject_unsafe_html_on_update_before_the_action_runs(): void
    {
        $user = User::factory()->create();
        $block = Block::factory()->create(['user_id' => $user->id, 'name' => 'safe', 'html_content' => '<p>Safe</p>']);
        $this->mock(UpdateBlock::class)->shouldNotReceive('handle');
        $unsafe = ['name' => 'safe', 'html_content' => '<p>Hi</p><script>alert(1)</script>'];

        $this->actingAs($user)->put(route('blocks.update', $block->id), $unsafe)->assertSessionHasErrors('html_content');
        Sanctum::actingAs($user, ['*']);
        $this->patchJson("/api/v1/blocks/{$block->id}", $unsafe)->assertUnprocessable()->assertJsonValidationErrors('html_content');

        $this->assertSame('<p>Safe</p>', $block->fresh()->html_content);
    }

    private function expectEachActionOnce(): void
    {
        foreach ([CreateBlock::class, UpdateBlock::class, DeleteBlock::class] as $action) {
            $this->partialMock($action)->shouldReceive('handle')->once()->passthru();
        }
    }
}
