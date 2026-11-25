<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Signed links in emails that were sent before a host move keep the old
 * host. A redirect brings them to the current host, and the signature check
 * must still accept them when the old URL is in `app.previous_urls`.
 */
class PreviousUrlSignatureTest extends TestCase
{
    use RefreshDatabase;

    private const PREVIOUS_URL = 'https://byomailer.com';

    public function test_preferences_link_signed_on_a_previous_url_is_accepted(): void
    {
        config()->set('app.previous_urls', [self::PREVIOUS_URL]);

        $contactMail = $this->createContactMail();

        $this->get($this->pathAndQuery($this->signOnPreviousUrl(
            fn (): string => URL::signedRoute('preferences.show', ['contactMail' => $contactMail->uuid]),
        )))->assertOk();
    }

    public function test_preferences_link_signed_on_an_unknown_url_is_rejected(): void
    {
        config()->set('app.previous_urls', []);

        $contactMail = $this->createContactMail();

        $this->get($this->pathAndQuery($this->signOnPreviousUrl(
            fn (): string => URL::signedRoute('preferences.show', ['contactMail' => $contactMail->uuid]),
        )))->assertForbidden();
    }

    public function test_changed_signature_is_rejected_with_a_previous_url(): void
    {
        config()->set('app.previous_urls', [self::PREVIOUS_URL]);

        $contactMail = $this->createContactMail();

        $url = $this->signOnPreviousUrl(
            fn (): string => URL::signedRoute('preferences.show', ['contactMail' => $contactMail->uuid]),
        );

        $this->get($this->pathAndQuery($url).'0')->assertForbidden();
    }

    public function test_link_signed_for_another_path_is_rejected_with_a_previous_url(): void
    {
        config()->set('app.previous_urls', [self::PREVIOUS_URL]);

        $contactMail = $this->createContactMail();
        $otherContactMail = $this->createContactMail();

        $url = $this->signOnPreviousUrl(
            fn (): string => URL::signedRoute('preferences.show', ['contactMail' => $otherContactMail->uuid]),
        );

        $this->get('/preferences/'.$contactMail->uuid.'?'.parse_url($url, PHP_URL_QUERY))
            ->assertForbidden();
    }

    public function test_preferences_link_signed_on_the_current_url_still_works(): void
    {
        config()->set('app.previous_urls', [self::PREVIOUS_URL]);

        $contactMail = $this->createContactMail();

        $this->get(URL::signedRoute('preferences.show', ['contactMail' => $contactMail->uuid]))
            ->assertOk();
    }

    public function test_invite_link_signed_on_a_previous_url_is_accepted(): void
    {
        config()->set('app.previous_urls', [self::PREVIOUS_URL]);

        $invited = $this->createInvitedUser();

        $this->get($this->pathAndQuery($this->signOnPreviousUrl(
            fn (): string => URL::temporarySignedRoute('invites.show', now()->addDay(), ['user' => $invited->uuid]),
        )))->assertOk();
    }

    public function test_expired_invite_link_signed_on_a_previous_url_is_rejected(): void
    {
        config()->set('app.previous_urls', [self::PREVIOUS_URL]);

        $invited = $this->createInvitedUser();

        $url = $this->signOnPreviousUrl(
            fn (): string => URL::temporarySignedRoute('invites.show', now()->addMinute(), ['user' => $invited->uuid]),
        );

        $this->travel(2)->minutes();

        $this->get($this->pathAndQuery($url))->assertStatus(410);
    }

    public function test_previous_urls_are_read_from_a_comma_separated_list(): void
    {
        $_ENV['APP_PREVIOUS_URLS'] = ' https://byomailer.com/ , https://old.example ,';
        $_SERVER['APP_PREVIOUS_URLS'] = $_ENV['APP_PREVIOUS_URLS'];

        try {
            $this->refreshApplication();

            $this->assertSame(
                ['https://byomailer.com', 'https://old.example'],
                config('app.previous_urls'),
            );
        } finally {
            unset($_ENV['APP_PREVIOUS_URLS'], $_SERVER['APP_PREVIOUS_URLS']);
            $this->refreshApplication();
        }
    }

    /**
     * @param  callable(): string  $sign
     */
    private function signOnPreviousUrl(callable $sign): string
    {
        URL::forceRootUrl(self::PREVIOUS_URL);
        URL::forceScheme('https');

        try {
            $url = $sign();
        } finally {
            URL::forceRootUrl(null);
            URL::forceScheme(null);
        }

        $this->assertStringStartsWith(self::PREVIOUS_URL.'/', $url);

        return $url;
    }

    private function pathAndQuery(string $url): string
    {
        return parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
    }

    private function createContactMail(): ContactMail
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id]);
        $newsletter = Newsletter::factory()->for($user)->create();

        return ContactMail::factory()->for($contact)->create([
            'mailable_type' => Newsletter::class,
            'mailable_id' => $newsletter->id,
        ]);
    }

    private function createInvitedUser(): User
    {
        return User::factory()->create([
            'role' => UserRole::Viewer,
            'invite_expires_at' => now()->addDay(),
            'email_verified_at' => null,
        ]);
    }
}
