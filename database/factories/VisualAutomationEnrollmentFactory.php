<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\VisualAutomation;
use App\Models\VisualAutomationEnrollment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<VisualAutomationEnrollment>
 */
class VisualAutomationEnrollmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'visual_automation_id' => VisualAutomation::factory(),
            'contact_id' => function (array $attributes) {
                $userId = DB::table('visual_automations')
                    ->where('id', $attributes['visual_automation_id'])
                    ->value('user_id');

                return Contact::factory()->create(['user_id' => $userId, 'client_id' => DB::table('users')->where('id', $userId)->value('client_id')])->id;
            },
            'status' => 'active',
            'enrolled_at' => now(),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }
}
