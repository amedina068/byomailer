<?php

namespace Database\Factories;

use App\Enums\ContactImportStatus;
use App\Models\ContactImport;
use App\Models\MailList;
use App\Models\User;
use Database\Factories\Concerns\ResolvesClientFromUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ContactImport>
 */
class ContactImportFactory extends Factory
{
    use ResolvesClientFromUser;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'client_id' => $this->clientOfUser(),
            'list_id' => MailList::factory(),
            'batch_id' => null,
            'status' => ContactImportStatus::Pending,
            'original_filename' => fake()->lexify('contacts-????.csv'),
            'total_count' => fake()->numberBetween(1, 500),
            'processed_count' => 0,
            'skipped_count' => 0,
            'error_message' => null,
        ];
    }

    public function processing(): self
    {
        return $this->state(fn (): array => [
            'status' => ContactImportStatus::Processing,
            'batch_id' => (string) Str::uuid(),
        ]);
    }

    public function completed(): self
    {
        return $this->state(fn (): array => [
            'status' => ContactImportStatus::Completed,
            'batch_id' => (string) Str::uuid(),
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn (): array => [
            'status' => ContactImportStatus::Failed,
            'batch_id' => (string) Str::uuid(),
            'error_message' => fake()->sentence(),
        ]);
    }
}
