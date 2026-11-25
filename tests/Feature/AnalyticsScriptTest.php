<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The app loads no analytics script. Plausible runs only on the marketing
 * site. The test sets the old Plausible variables before the app boots, so an
 * operator who keeps them in the production .env gets no script either.
 */
class AnalyticsScriptTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_VARIABLES = [
        'PLAUSIBLE_DOMAIN' => 'app.example.com',
        'PLAUSIBLE_SCRIPT_URL' => 'https://plausible.example.com/js/script.js',
    ];

    protected function setUp(): void
    {
        foreach (self::OLD_VARIABLES as $name => $value) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        parent::setUp();

        $this->completeInstanceSetup();
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::OLD_VARIABLES) as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }

        parent::tearDown();
    }

    public function test_no_page_loads_plausible_when_the_old_variables_are_set(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('plausible', false);

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('plausible', false);

        auth()->logout();

        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['unsubscribed_at' => null]);
        $newsletter = Newsletter::factory()->for($user)->create();
        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->create(['status' => 'sent']);

        $this->get('/unsubscribe/'.$contactMail->uuid)
            ->assertOk()
            ->assertDontSee('plausible', false);
    }

    public function test_the_page_has_no_hosted_service_schema(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('byomailer.com', false);
    }
}
