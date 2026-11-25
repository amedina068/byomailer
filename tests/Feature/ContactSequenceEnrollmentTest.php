<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\MailList;
use App\Models\Sequence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactSequenceEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_enroll_multiple_contacts_into_a_sequence(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $contactA = Contact::factory()->for($user)->create();
        $contactB = Contact::factory()->for($user)->create();

        $this->actingAs($user);

        $this->post(route('contacts.sequences.enroll'), [
            'sequence_id' => $sequence->id,
            'contact_ids' => [$contactA->id, $contactB->id],
        ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('contact_sequence', [
            'sequence_id' => $sequence->id,
            'contact_id' => $contactA->id,
        ]);
        $this->assertDatabaseHas('contact_sequence', [
            'sequence_id' => $sequence->id,
            'contact_id' => $contactB->id,
        ]);
    }

    public function test_already_enrolled_contacts_are_not_duplicated(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $contact = Contact::factory()->for($user)->create();
        $sequence->contacts()->attach($contact->id);

        $this->actingAs($user);

        $this->post(route('contacts.sequences.enroll'), [
            'sequence_id' => $sequence->id,
            'contact_ids' => [$contact->id],
        ])->assertRedirect();

        $this->assertSame(1, $sequence->contacts()->where('contacts.id', $contact->id)->count());
    }

    public function test_one_contact_skipped_by_filters_does_not_stop_the_others(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();

        $sequence = Sequence::factory()->for($user)->create([
            'status' => 'live',
            'filters' => [
                [
                    'connector' => 'and',
                    'category' => 'lists',
                    'mode' => 'any',
                    'values' => [$list->id],
                ],
            ],
        ]);

        $matching = Contact::factory()->for($user)->create();
        $matching->lists()->attach($list);

        $notMatching = Contact::factory()->for($user)->create();

        $this->actingAs($user);

        $this->post(route('contacts.sequences.enroll'), [
            'sequence_id' => $sequence->id,
            'contact_ids' => [$notMatching->id, $matching->id],
        ])->assertRedirect();

        $this->assertDatabaseHas('contact_sequence', [
            'sequence_id' => $sequence->id,
            'contact_id' => $matching->id,
        ]);
        $this->assertDatabaseMissing('contact_sequence', [
            'sequence_id' => $sequence->id,
            'contact_id' => $notMatching->id,
        ]);
    }

    public function test_cannot_enroll_into_another_users_sequence(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $sequence = Sequence::factory()->for($other)->create(['status' => 'live']);
        $contact = Contact::factory()->for($user)->create();

        $this->actingAs($user);

        $this->post(route('contacts.sequences.enroll'), [
            'sequence_id' => $sequence->id,
            'contact_ids' => [$contact->id],
        ])->assertNotFound();

        $this->assertDatabaseMissing('contact_sequence', [
            'sequence_id' => $sequence->id,
            'contact_id' => $contact->id,
        ]);
    }

    public function test_contacts_from_another_client_are_ignored(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);

        $foreignUser = User::factory()->create();
        $foreignContact = Contact::factory()->for($foreignUser)->create();

        $this->actingAs($user);

        $this->post(route('contacts.sequences.enroll'), [
            'sequence_id' => $sequence->id,
            'contact_ids' => [$foreignContact->id],
        ])->assertRedirect();

        $this->assertDatabaseMissing('contact_sequence', [
            'sequence_id' => $sequence->id,
            'contact_id' => $foreignContact->id,
        ]);
    }

    public function test_enrollment_requires_a_sequence_and_at_least_one_contact(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $this->post(route('contacts.sequences.enroll'), [])
            ->assertSessionHasErrors(['sequence_id', 'contact_ids']);
    }
}
