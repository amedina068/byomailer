<?php

namespace Tests\Feature\Webhooks;

use App\Events\ContactResubscribed;
use App\Events\ContactSubscribed;
use App\Events\ContactUnsubscribed;
use App\Jobs\ProcessContactImportBatchJob;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\ContactMail;
use App\Models\MailList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unsubscribe_transition_dispatches_event(): void
    {
        Event::fake([ContactUnsubscribed::class, ContactResubscribed::class]);
        $contact = Contact::factory()->create(['unsubscribed_at' => null]);
        $this->actingAsTenant($contact->client_id);

        $contact->forceFill(['unsubscribed_at' => now()])->save();

        Event::assertDispatched(ContactUnsubscribed::class, fn ($e) => $e->contact->is($contact));
        Event::assertNotDispatched(ContactResubscribed::class);
    }

    public function test_resubscribe_transition_dispatches_event(): void
    {
        Event::fake([ContactUnsubscribed::class, ContactResubscribed::class]);
        $contact = Contact::factory()->create(['unsubscribed_at' => now()]);

        $contact->forceFill(['unsubscribed_at' => null])->save();

        Event::assertDispatched(ContactResubscribed::class, fn ($e) => $e->contact->is($contact));
        Event::assertNotDispatched(ContactUnsubscribed::class);
    }

    public function test_ses_suppressed_unsubscribe_does_not_dispatch(): void
    {
        Event::fake([ContactUnsubscribed::class]);
        $contact = Contact::factory()->create(['unsubscribed_at' => null]);
        $this->actingAsTenant($contact->client_id);

        $contact->webhooksSuppressed = true;
        $contact->forceFill(['unsubscribed_at' => now()])->save();

        Event::assertNotDispatched(ContactUnsubscribed::class);
    }

    public function test_creating_contact_does_not_dispatch_subscription_events(): void
    {
        Event::fake([ContactUnsubscribed::class, ContactResubscribed::class, ContactSubscribed::class]);

        Contact::factory()->create(['unsubscribed_at' => null]);
        Contact::factory()->create(['unsubscribed_at' => now()]);

        Event::assertNotDispatched(ContactUnsubscribed::class);
        Event::assertNotDispatched(ContactResubscribed::class);
        Event::assertNotDispatched(ContactSubscribed::class);
    }

    public function test_no_op_save_does_not_dispatch(): void
    {
        Event::fake([ContactUnsubscribed::class, ContactResubscribed::class]);
        $contact = Contact::factory()->create(['unsubscribed_at' => null]);

        $contact->forceFill(['name' => 'Renamed'])->save();

        Event::assertNotDispatched(ContactUnsubscribed::class);
        Event::assertNotDispatched(ContactResubscribed::class);
    }

    public function test_resubscribe_then_no_op_clear_dispatches_exactly_once(): void
    {
        Event::fake([ContactResubscribed::class]);
        $contact = Contact::factory()->create(['unsubscribed_at' => now()->subDay()]);

        // Real resubscribe: non-null -> null fires once.
        $contact->forceFill(['unsubscribed_at' => null])->save();
        Event::assertDispatched(ContactResubscribed::class, 1);

        // No-op clear of an already-null column must NOT re-fire (wasChanged guard).
        $contact->refresh();
        $contact->forceFill(['unsubscribed_at' => null])->save();
        Event::assertDispatched(ContactResubscribed::class, 1);
    }

    public function test_resubscribe_on_already_subscribed_contact_does_not_dispatch(): void
    {
        Event::fake([ContactResubscribed::class]);
        $contact = Contact::factory()->create(['unsubscribed_at' => null]);

        $contact->forceFill(['unsubscribed_at' => null])->save();

        Event::assertNotDispatched(ContactResubscribed::class);
    }

    public function test_contact_import_does_not_dispatch_subscribed(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $contactImport = ContactImport::factory()
            ->processing()
            ->for($user)
            ->for($list, 'mailList')
            ->create(['total_count' => 1, 'processed_count' => 0, 'skipped_count' => 0]);

        $rows = [[
            'email' => 'imported@example.com',
            'name' => 'Imported',
            'tags' => null,
            'attributes' => [],
        ]];

        Event::fake([ContactSubscribed::class]);

        [$job] = (new ProcessContactImportBatchJob($user, $list, $contactImport, $rows))->withFakeBatch();
        app()->call([$job, 'handle']);

        $this->assertTrue(
            Contact::withoutGlobalScopes()->where('email', 'imported@example.com')->exists(),
            'Import should have created the contact.',
        );
        Event::assertNotDispatched(ContactSubscribed::class);
    }

    public function test_api_single_opt_in_dispatches_subscribed(): void
    {
        Event::fake([ContactSubscribed::class]);
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create(['opt_in_enabled' => false]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/v1/lists/{$list->id}/contacts", [
            'name' => 'Jane',
            'email' => 'jane@example.com',
        ])->assertCreated();

        Event::assertDispatched(ContactSubscribed::class, 1);
    }

    public function test_api_double_opt_in_does_not_dispatch_subscribed_on_create(): void
    {
        Event::fake([ContactSubscribed::class]);
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create(['opt_in_enabled' => true]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/v1/lists/{$list->id}/contacts", [
            'name' => 'Jane',
            'email' => 'jane@example.com',
        ])->assertCreated();

        Event::assertNotDispatched(ContactSubscribed::class);
    }

    public function test_opt_in_confirmation_dispatches_subscribed_once(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create(['opt_in_enabled' => true]);
        $contact = Contact::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id]);
        $contact->lists()->attach($list->id, ['opted_in_at' => null]);

        $mail = ContactMail::factory()->create([
            'contact_id' => $contact->id,
            'client_id' => $contact->client_id,
            'mailable_type' => MailList::class,
            'mailable_id' => $list->id,
        ]);

        Event::fake([ContactSubscribed::class]);

        // The GET page only asks the contact to confirm.
        $this->get("/opt-in/{$mail->uuid}")->assertOk();
        Event::assertNotDispatched(ContactSubscribed::class);

        $this->post("/opt-in/{$mail->uuid}")->assertOk();
        Event::assertDispatched(ContactSubscribed::class, 1);

        // Second confirmation must not re-fire.
        $this->post("/opt-in/{$mail->uuid}")->assertOk();
        Event::assertDispatched(ContactSubscribed::class, 1);
    }
}
