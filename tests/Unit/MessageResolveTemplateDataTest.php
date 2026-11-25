<?php

namespace Tests\Unit;

use App\Models\Contact;
use App\Models\ContactAttribute;
use App\Models\ContactMail;
use App\Models\Message;
use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageResolveTemplateDataTest extends TestCase
{
    use RefreshDatabase;

    private function makeMessage(User $user, string $html): Message
    {
        $newsletter = Newsletter::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
        ]);

        return Message::factory()->create([
            'user_id' => $user->id,
            'subject' => 'Hello {{ name }}',
            'html_content' => $html,
            'messageable_type' => Newsletter::class,
            'messageable_id' => $newsletter->id,
        ]);
    }

    private function makeContactMail(User $user, Contact $contact, Message $message): ContactMail
    {
        return ContactMail::factory()->create([
            'contact_id' => $contact->id,
            'client_id' => $user->client_id,
            'message_id' => $message->id,
        ]);
    }

    public function test_contact_value_wins_over_content_variable_default(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'extra_attributes' => ['company' => 'Contact Co'],
        ]);
        $message = $this->makeMessage($user, '<p>{{ company }}</p>');
        $contactMail = $this->makeContactMail($user, $contact, $message);

        $resolved = $message->resolveTemplateData($contact, $contactMail, ['company' => 'Default Co']);

        $this->assertSame('Contact Co', $resolved['company']);
    }

    public function test_content_variable_default_fills_blank_contact_value(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'extra_attributes' => [],
        ]);
        $message = $this->makeMessage($user, '<p>{{ company }}</p>');
        $contactMail = $this->makeContactMail($user, $contact, $message);

        $resolved = $message->resolveTemplateData($contact, $contactMail, ['company' => 'Default Co']);

        $this->assertSame('Default Co', $resolved['company']);
    }

    public function test_missing_everywhere_resolves_to_empty_string(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'extra_attributes' => [],
        ]);
        $message = $this->makeMessage($user, '<p>{{ company }}</p>');
        $contactMail = $this->makeContactMail($user, $contact, $message);

        $resolved = $message->resolveTemplateData($contact, $contactMail, []);

        $this->assertSame('', $resolved['company']);
    }

    public function test_global_default_fills_value_blank_after_content_variables(): void
    {
        $user = User::factory()->create();
        ContactAttribute::factory()->withDefault('Global Co')->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'company',
        ]);
        $contact = Contact::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'extra_attributes' => [],
        ]);
        $message = $this->makeMessage($user, '<p>{{ company }}</p>');
        $contactMail = $this->makeContactMail($user, $contact, $message);

        $globalDefaults = $message->globalAttributeDefaults($user->client_id);
        $resolved = $message->resolveTemplateData($contact, $contactMail, [], $globalDefaults);

        $this->assertSame('Global Co', $resolved['company']);
    }

    public function test_content_variable_default_beats_global_default(): void
    {
        $user = User::factory()->create();
        ContactAttribute::factory()->withDefault('Global Co')->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'company',
        ]);
        $contact = Contact::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'extra_attributes' => [],
        ]);
        $message = $this->makeMessage($user, '<p>{{ company }}</p>');
        $contactMail = $this->makeContactMail($user, $contact, $message);

        $globalDefaults = $message->globalAttributeDefaults($user->client_id);
        $resolved = $message->resolveTemplateData($contact, $contactMail, ['company' => 'Newsletter Co'], $globalDefaults);

        $this->assertSame('Newsletter Co', $resolved['company']);
    }

    public function test_contact_zero_string_value_beats_all_defaults(): void
    {
        $user = User::factory()->create();
        ContactAttribute::factory()->withDefault('FULL10')->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'coupon',
        ]);
        $contact = Contact::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'extra_attributes' => ['coupon' => '0'],
        ]);
        $message = $this->makeMessage($user, '<p>{{ coupon }}</p>');
        $contactMail = $this->makeContactMail($user, $contact, $message);

        $globalDefaults = $message->globalAttributeDefaults($user->client_id);
        $resolved = $message->resolveTemplateData($contact, $contactMail, ['coupon' => 'HALF5'], $globalDefaults);

        $this->assertSame('0', $resolved['coupon']);
    }

    public function test_global_defaults_map_is_bounded_to_detected_fields(): void
    {
        $user = User::factory()->create();
        ContactAttribute::factory()->withDefault('Global Co')->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'company',
        ]);
        ContactAttribute::factory()->withDefault('Unrelated')->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'undetected_attribute',
        ]);
        $message = $this->makeMessage($user, '<p>{{ company }}</p>');

        $globalDefaults = $message->globalAttributeDefaults($user->client_id);

        $this->assertSame(['company' => 'Global Co'], $globalDefaults);
    }

    public function test_reserved_url_keys_are_excluded_from_global_defaults(): void
    {
        $user = User::factory()->create();
        ContactAttribute::factory()->withDefault('https://evil.example')->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'unsubscribe_url',
        ]);
        ContactAttribute::factory()->withDefault('https://evil.example/prefs')->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'preferences_url',
        ]);
        $message = $this->makeMessage($user, '<p>{{ unsubscribe_url }} {{ preferences_url }}</p>');

        $this->assertSame([], $message->globalAttributeDefaults($user->client_id));
    }

    public function test_zero_string_global_default_fills_blank_value(): void
    {
        $user = User::factory()->create();
        ContactAttribute::factory()->withDefault('0')->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'coupon',
        ]);
        $contact = Contact::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'extra_attributes' => [],
        ]);
        $message = $this->makeMessage($user, '<p>{{ coupon }}</p>');
        $contactMail = $this->makeContactMail($user, $contact, $message);

        $globalDefaults = $message->globalAttributeDefaults($user->client_id);
        $resolved = $message->resolveTemplateData($contact, $contactMail, [], $globalDefaults);

        $this->assertSame('0', $resolved['coupon']);
    }

    public function test_case_mismatched_attribute_name_resolves_to_empty_string(): void
    {
        $user = User::factory()->create();
        ContactAttribute::factory()->withDefault('Ada')->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'firstname',
        ]);
        $contact = Contact::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'extra_attributes' => [],
        ]);
        $message = $this->makeMessage($user, '<p>{{ FirstName }}</p>');
        $contactMail = $this->makeContactMail($user, $contact, $message);

        $globalDefaults = $message->globalAttributeDefaults($user->client_id);
        $resolved = $message->resolveTemplateData($contact, $contactMail, [], $globalDefaults);

        $this->assertSame('', $resolved['FirstName']);
    }

    public function test_null_client_id_returns_no_global_defaults(): void
    {
        $user = User::factory()->create();
        $message = $this->makeMessage($user, '<p>{{ company }}</p>');

        $this->assertSame([], $message->globalAttributeDefaults(null));
    }

    public function test_null_content_variable_default_casts_to_empty_string(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'extra_attributes' => [],
        ]);
        $message = $this->makeMessage($user, '<p>{{ company }}</p>');
        $contactMail = $this->makeContactMail($user, $contact, $message);

        $resolved = $message->resolveTemplateData($contact, $contactMail, ['company' => null]);

        $this->assertSame('', $resolved['company']);
    }
}
