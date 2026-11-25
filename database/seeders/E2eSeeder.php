<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The known state for the Playwright end-to-end tests in tests/e2e/.
 *
 * It makes one owner account with a known password, one tag and one contact.
 * It makes no AWS credentials and no sender identity, so no page calls AWS.
 */
class E2eSeeder extends Seeder
{
    public const EMAIL = 'e2e@example.com';

    public const PASSWORD = 'password';

    public const CONTACT_EMAIL = 'seeded.contact@example.com';

    public const TAG_NAME = 'e2e-seeded-tag';

    public function run(): void
    {
        // The account has a known password.
        if (app()->isProduction()) {
            throw new RuntimeException('The end-to-end seeder does not run in production.');
        }

        $client = Client::factory()->create([
            'name' => 'E2E Company',
            'timezone' => 'UTC',
        ]);

        $user = User::factory()->owner()->for($client)->create([
            'name' => 'E2E Owner',
            'email' => self::EMAIL,
        ]);

        Tenant::run($client->id, function () use ($user): void {
            Tag::factory()->for($user)->create(['name' => self::TAG_NAME]);

            Contact::factory()->for($user)->create([
                'email' => self::CONTACT_EMAIL,
                'name' => 'Seeded Contact',
            ]);
        });
    }
}
