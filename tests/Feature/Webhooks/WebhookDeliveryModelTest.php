<?php

namespace Tests\Feature\Webhooks;

use App\Enums\WebhookDeliveryStatus;
use App\Models\WebhookDelivery;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookDeliveryModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_casts_to_array(): void
    {
        $delivery = WebhookDelivery::factory()->create(['payload' => ['foo' => 'bar']]);

        $this->assertSame(['foo' => 'bar'], $delivery->fresh()->payload);
    }

    public function test_prunable_scope_targets_only_old_rows(): void
    {
        $this->travelTo(now());

        $old = WebhookDelivery::factory()->create();
        $old->forceFill(['created_at' => now()->subDays(31)])->save();

        $recent = WebhookDelivery::factory()->create();
        $recent->forceFill(['created_at' => now()->subDays(29)])->save();

        $prunableIds = (new WebhookDelivery)->prunable()->pluck('id')->all();

        $this->assertContains($old->id, $prunableIds);
        $this->assertNotContains($recent->id, $prunableIds);
    }

    public function test_model_prune_removes_old_deliveries_of_every_client(): void
    {
        $this->travelTo(now());

        $first = WebhookDelivery::factory()->create();
        $second = WebhookDelivery::factory()->create();
        WebhookDelivery::withoutGlobalScopes()->whereKey([$first->id, $second->id])->update(['created_at' => now()->subDays(31)]);

        $this->artisan('model:prune', ['--model' => [WebhookDelivery::class]])->assertSuccessful();

        $this->assertDatabaseMissing('webhook_deliveries', ['id' => $first->id]);
        $this->assertDatabaseMissing('webhook_deliveries', ['id' => $second->id]);
    }

    public function test_model_prune_is_scheduled_for_deliveries(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event) => $event->command ?? '')
            ->filter(fn (string $command) => str_contains($command, 'model:prune'));

        $this->assertTrue(
            $commands->contains(fn (string $command) => str_contains($command, 'WebhookDelivery')),
            'Expected model:prune to be scheduled for WebhookDelivery.'
        );
    }

    public function test_factory_status_states(): void
    {
        $this->assertSame(WebhookDeliveryStatus::Success, WebhookDelivery::factory()->success()->create()->status);
        $this->assertSame(WebhookDeliveryStatus::Failed, WebhookDelivery::factory()->failed()->create()->status);
        $this->assertSame(WebhookDeliveryStatus::Pending, WebhookDelivery::factory()->pending()->create()->status);
    }
}
