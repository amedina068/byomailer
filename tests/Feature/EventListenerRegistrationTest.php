<?php

namespace Tests\Feature;

use App\Events\ContactAttributesChanged;
use App\Events\ContactListsSynced;
use App\Events\ContactResubscribed;
use App\Events\ContactSequenceFinished;
use App\Events\ContactSequencesSynced;
use App\Events\ContactSubscribed;
use App\Events\ContactTagsSynced;
use App\Events\ContactUnsubscribed;
use App\Events\NewsletterSent;
use App\Events\SequenceStepSent;
use App\Listeners\RunAutomationsForSyncedTags;
use App\Listeners\WebhookEventListener;
use App\Models\Contact;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Events\TokenAuthenticated;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every listener must be registered one time.
 *
 * Laravel event discovery scans `app/Listeners`. When the same listener is also
 * named in `EventServiceProvider::$listen`, the dispatcher holds it two times, as
 * `Listener` and as `Listener@handle`. Each event then queues two jobs: an automation
 * runs two times, a `sequence_finished` action runs two times, and a webhook
 * endpoint receives the same delivery two times.
 */
class EventListenerRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{class-string, int}>
     */
    public static function expectedListenerCounts(): array
    {
        return [
            'lists synced' => [ContactListsSynced::class, 3],
            'tags synced' => [ContactTagsSynced::class, 3],
            'sequences synced' => [ContactSequencesSynced::class, 3],
            'sequence finished' => [ContactSequenceFinished::class, 4],
            'attributes changed' => [ContactAttributesChanged::class, 1],
            'subscribed' => [ContactSubscribed::class, 1],
            'unsubscribed' => [ContactUnsubscribed::class, 1],
            'resubscribed' => [ContactResubscribed::class, 1],
            'newsletter sent' => [NewsletterSent::class, 1],
            'sequence step sent' => [SequenceStepSent::class, 1],
            'session authenticated' => [Authenticated::class, 1],
            'token authenticated' => [TokenAuthenticated::class, 1],
            'logout' => [Logout::class, 1],
        ];
    }

    #[DataProvider('expectedListenerCounts')]
    public function test_each_event_has_each_listener_one_time(string $event, int $expected): void
    {
        // `Dispatcher::getListeners()` adds the listeners of an interface only for an
        // event class that is already loaded, so load it first.
        $this->assertTrue(class_exists($event));

        $this->assertCount($expected, app('events')->getListeners($event));
    }

    public function test_a_tag_sync_queues_the_tag_automations_one_time(): void
    {
        $contact = Contact::factory()->create();

        Queue::fake();

        event(new ContactTagsSynced($contact, ['attached' => [1], 'detached' => [], 'updated' => []]));

        $this->assertSame(1, $this->queuedListenerCount(RunAutomationsForSyncedTags::class));
    }

    public function test_a_subscribe_queues_one_webhook_delivery(): void
    {
        $contact = Contact::factory()->create();

        Queue::fake();

        event(new ContactSubscribed($contact));

        $this->assertSame(1, $this->queuedListenerCount(WebhookEventListener::class));
    }

    private function queuedListenerCount(string $listener): int
    {
        return Queue::pushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === $listener)->count();
    }
}
