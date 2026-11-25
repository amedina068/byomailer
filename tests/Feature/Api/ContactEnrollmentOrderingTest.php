<?php

namespace Tests\Feature\Api;

use App\Events\ContactListsSynced;
use App\Events\ContactTagsSynced;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\MailList;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class ContactEnrollmentOrderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_tag_trigger_sees_list_attached_in_the_same_api_request(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create(['opt_in_enabled' => false]);
        $contact = Contact::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create(['name' => 'clicked_payment_button']);
        $sequence = Sequence::factory()->for($user)->create([
            'status' => 'live',
            'filters' => [['connector' => 'and', 'category' => 'lists', 'mode' => 'any', 'values' => [$list->id]]],
        ]);
        SequenceStep::factory()->for($sequence)->create(['status' => 'live', 'delay_days' => 0]);
        Automation::factory()->for($user)->create([
            'is_live' => true,
            'condition' => ['type' => 'tag_added', 'value' => $tag->id],
            'actions' => [['type' => 'add_sequence', 'value' => $sequence->id]],
        ]);
        Sanctum::actingAs($user, ['*']);
        $this->postJson("/api/v1/lists/{$list->id}/contacts", ['email' => $contact->email, 'tags' => [$tag->name]])->assertOk();
        $this->assertTrue($contact->sequences()->whereKey($sequence->id)->exists());
        $this->assertDatabaseHas('contact_mails', ['contact_id' => $contact->id, 'status' => 'pending']);
        $this->postJson("/api/v1/lists/{$list->id}/contacts", ['email' => $contact->email, 'tags' => [$tag->name]])->assertOk();
        $this->assertDatabaseCount('contact_mails', 1);
    }

    public function test_tag_and_list_events_are_dropped_on_rollback(): void
    {
        $contact = Contact::factory()->for(User::factory())->create();
        $received = [];
        foreach ([ContactTagsSynced::class, ContactListsSynced::class] as $event) {
            Event::listen($event, function ($event) use (&$received): void {
                $received[] = $event;
            });
        }
        try {
            DB::transaction(function () use ($contact, &$received): void {
                ContactTagsSynced::dispatch($contact, ['attached' => [1]]);
                ContactListsSynced::dispatch($contact, ['attached' => [1]]);
                $this->assertSame([], $received);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame([], $received);
    }
}
