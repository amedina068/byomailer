<?php

namespace Tests\Feature;

use App\Actions\UnsubscribeContact;
use App\Events\ContactUnsubscribed;
use App\Models\BlacklistedEmail;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The web dashboard and the API share one unsubscribe rule and one
 * subscribe rule.
 */
class ContactSubscriptionActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_second_web_unsubscribe_keeps_the_first_date_and_fires_the_event_once(): void
    {
        Event::fake([ContactUnsubscribed::class]);
        Carbon::setTestNow(now()->startOfSecond());
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['unsubscribed_at' => null]);

        $this->actingAs($user)->post(route('contacts.unsubscribe', $contact))->assertRedirect();
        $firstDate = $contact->fresh()->unsubscribed_at;
        Carbon::setTestNow(now()->addHour());
        $this->actingAs($user)->post(route('contacts.unsubscribe', $contact))->assertRedirect();

        $this->assertEquals($firstDate, $contact->fresh()->unsubscribed_at);
        Event::assertDispatchedTimes(ContactUnsubscribed::class, 1);
    }

    public function test_unsubscribe_with_a_stale_model_keeps_the_first_date(): void
    {
        Event::fake([ContactUnsubscribed::class]);
        Carbon::setTestNow(now()->startOfSecond());
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['unsubscribed_at' => null]);
        $this->actingAsTenant($user->client_id);
        $stale = $contact->fresh();
        $contact->forceFill(['unsubscribed_at' => now()->subDay(), 'unsubscribed_by_contact' => true])->saveQuietly();

        app(UnsubscribeContact::class)->handle($user, $stale);

        $fresh = $contact->fresh();
        $this->assertEquals(now()->subDay(), $fresh->unsubscribed_at);
        $this->assertTrue($fresh->unsubscribed_by_contact);
        Event::assertNotDispatched(ContactUnsubscribed::class);
    }

    public function test_the_api_unsubscribe_fires_the_event_once(): void
    {
        Event::fake([ContactUnsubscribed::class]);
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['unsubscribed_at' => null]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/v1/contacts/{$contact->id}/unsubscribe")->assertOk();
        $this->postJson("/api/v1/contacts/{$contact->id}/unsubscribe")->assertOk();

        Event::assertDispatchedTimes(ContactUnsubscribed::class, 1);
    }

    public function test_the_api_subscribe_asks_for_a_confirmation_before_it_removes_a_bounce_suppression(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['email' => 'bounced@example.com', 'unsubscribed_at' => now()]);
        $suppression = BlacklistedEmail::factory()->for($user)->create(['email' => 'bounced@example.com', 'reason' => 'bounce']);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/v1/contacts/{$contact->id}/subscribe")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['remove_suppression' => 'Confirm that you remove the address from the suppression list.']);
        $this->assertModelExists($suppression);

        $this->postJson("/api/v1/contacts/{$contact->id}/subscribe", ['remove_suppression' => true])
            ->assertOk()
            ->assertJsonPath('data.unsubscribed_at', null);
        $this->assertModelMissing($suppression);
    }

    public function test_the_api_subscribe_refuses_an_address_that_complained(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['email' => 'angry@example.com', 'unsubscribed_at' => now()]);
        BlacklistedEmail::factory()->for($user)->create(['email' => 'angry@example.com', 'reason' => 'complaint']);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/v1/contacts/{$contact->id}/subscribe", ['remove_suppression' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['contact' => 'The address is on the suppression list after a complaint, or the server operator blocked it. BYOMailer cannot mail it again.']);

        $this->assertNotNull($contact->fresh()->unsubscribed_at);
    }
}
