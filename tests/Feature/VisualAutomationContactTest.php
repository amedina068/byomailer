<?php

namespace Tests\Feature;

use App\Enums\EnrollmentExitReason;
use App\Models\Contact;
use App\Models\User;
use App\Models\VisualAutomation;
use App\Models\VisualAutomationContactStep;
use App\Models\VisualAutomationEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VisualAutomationContactTest extends TestCase
{
    use RefreshDatabase;

    private function createAutomationWithWorkflow(User $user): VisualAutomation
    {
        return VisualAutomation::factory()->for($user)->create([
            'workflow' => [
                'nodes' => [
                    [
                        'id' => 'trigger-1',
                        'type' => 'trigger',
                        'position' => ['x' => 0, 'y' => 0],
                        'data' => ['trigger_type' => 'contact_added_to_list', 'value' => 1],
                    ],
                    [
                        'id' => 'action-1',
                        'type' => 'action',
                        'position' => ['x' => 0, 'y' => 280],
                        'data' => ['action_type' => 'add_tag', 'value' => 1],
                    ],
                    [
                        'id' => 'delay-1',
                        'type' => 'delay',
                        'position' => ['x' => 0, 'y' => 560],
                        'data' => ['duration' => 1, 'unit' => 'days'],
                    ],
                ],
                'edges' => [
                    ['id' => 'edge-1', 'source' => 'trigger-1', 'target' => 'action-1'],
                    ['id' => 'edge-2', 'source' => 'action-1', 'target' => 'delay-1'],
                ],
            ],
        ]);
    }

    public function test_contacts_index_shows_enrolled_contacts(): void
    {
        $user = User::factory()->create();
        $automation = $this->createAutomationWithWorkflow($user);

        $contact = Contact::factory()->for($user)->create();
        $enrollment = VisualAutomationEnrollment::factory()->for($automation)->create([
            'contact_id' => $contact->id,
            'status' => 'active',
        ]);

        // Contact not enrolled
        Contact::factory()->for($user)->create();

        $response = $this->actingAs($user)->get(route('visual-automations.contacts.index', $automation));

        $response->assertOk();
        $response->assertInertia(function (Assert $page) use ($automation, $contact): void {
            $page
                ->component('visual-automations/Contacts')
                ->where('automation.id', $automation->id)
                ->where('filter', 'all')
                ->has('contacts.data', 1)
                ->where('contacts.data.0.id', $contact->id)
                ->has('stats');
        });
    }

    public function test_contacts_index_filters_by_active(): void
    {
        $user = User::factory()->create();
        $automation = $this->createAutomationWithWorkflow($user);

        $activeContact = Contact::factory()->for($user)->create();
        VisualAutomationEnrollment::factory()->for($automation)->create([
            'contact_id' => $activeContact->id,
            'status' => 'active',
        ]);

        $completedContact = Contact::factory()->for($user)->create();
        VisualAutomationEnrollment::factory()->for($automation)->create([
            'contact_id' => $completedContact->id,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)->get(
            route('visual-automations.contacts.index', [$automation, 'filter' => 'active']),
        );

        $response->assertInertia(function (Assert $page) use ($activeContact): void {
            $page
                ->where('filter', 'active')
                ->has('contacts.data', 1)
                ->where('contacts.data.0.id', $activeContact->id);
        });
    }

    public function test_contacts_index_filters_by_completed(): void
    {
        $user = User::factory()->create();
        $automation = $this->createAutomationWithWorkflow($user);

        Contact::factory()->for($user)->create();
        VisualAutomationEnrollment::factory()->for($automation)->create([
            'contact_id' => Contact::factory()->for($user)->create()->id,
            'status' => 'active',
        ]);

        $completedContact = Contact::factory()->for($user)->create();
        VisualAutomationEnrollment::factory()->for($automation)->create([
            'contact_id' => $completedContact->id,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)->get(
            route('visual-automations.contacts.index', [$automation, 'filter' => 'completed']),
        );

        $response->assertInertia(function (Assert $page) use ($completedContact): void {
            $page
                ->where('filter', 'completed')
                ->has('contacts.data', 1)
                ->where('contacts.data.0.id', $completedContact->id);
        });
    }

    public function test_node_contacts_shows_contacts_at_specific_node(): void
    {
        $user = User::factory()->create();
        $automation = $this->createAutomationWithWorkflow($user);

        $contact = Contact::factory()->for($user)->create();
        $enrollment = VisualAutomationEnrollment::factory()->for($automation)->create([
            'contact_id' => $contact->id,
            'status' => 'active',
        ]);

        $enrollment->steps()->create([
            'node_id' => 'action-1',
            'status' => VisualAutomationContactStep::STATUS_COMPLETED,
        ]);

        // Contact at a different node
        $otherContact = Contact::factory()->for($user)->create();
        $otherEnrollment = VisualAutomationEnrollment::factory()->for($automation)->create([
            'contact_id' => $otherContact->id,
            'status' => 'active',
        ]);
        $otherEnrollment->steps()->create([
            'node_id' => 'delay-1',
            'status' => VisualAutomationContactStep::STATUS_WAITING,
        ]);

        $response = $this->actingAs($user)->get(
            route('visual-automations.nodes.contacts.index', [$automation, 'action-1']),
        );

        $response->assertOk();
        $response->assertInertia(function (Assert $page) use ($contact): void {
            $page
                ->component('visual-automations/NodeContacts')
                ->where('nodeLabel', 'Action')
                ->has('contacts.data', 1)
                ->where('contacts.data.0.id', $contact->id)
                ->has('stats');
        });
    }

    public function test_node_contacts_filters_by_active(): void
    {
        $user = User::factory()->create();
        $automation = $this->createAutomationWithWorkflow($user);

        $activeContact = Contact::factory()->for($user)->create();
        $enrollment = VisualAutomationEnrollment::factory()->for($automation)->create([
            'contact_id' => $activeContact->id,
            'status' => 'active',
        ]);
        $enrollment->steps()->create([
            'node_id' => 'action-1',
            'status' => VisualAutomationContactStep::STATUS_PENDING,
        ]);

        $completedContact = Contact::factory()->for($user)->create();
        $enrollment2 = VisualAutomationEnrollment::factory()->for($automation)->create([
            'contact_id' => $completedContact->id,
            'status' => 'active',
        ]);
        $enrollment2->steps()->create([
            'node_id' => 'action-1',
            'status' => VisualAutomationContactStep::STATUS_COMPLETED,
        ]);

        $response = $this->actingAs($user)->get(
            route('visual-automations.nodes.contacts.index', [$automation, 'action-1']).'?filter=active',
        );

        $response->assertOk();
        $response->assertInertia(function (Assert $page) use ($activeContact): void {
            $page
                ->where('filter', 'active')
                ->has('contacts.data', 1)
                ->where('contacts.data.0.id', $activeContact->id);
        });
    }

    public function test_node_contacts_returns_404_for_invalid_node(): void
    {
        $user = User::factory()->create();
        $automation = $this->createAutomationWithWorkflow($user);

        $response = $this->actingAs($user)->get(
            route('visual-automations.nodes.contacts.index', [$automation, 'nonexistent-node']),
        );

        $response->assertNotFound();
    }

    public function test_stats_include_correct_counts(): void
    {
        $user = User::factory()->create();
        $automation = $this->createAutomationWithWorkflow($user);

        // 2 active, 1 completed
        foreach (range(1, 2) as $i) {
            $contact = Contact::factory()->for($user)->create();
            VisualAutomationEnrollment::factory()->for($automation)->create([
                'contact_id' => $contact->id,
                'status' => 'active',
            ]);
        }

        $completedContact = Contact::factory()->for($user)->create();
        VisualAutomationEnrollment::factory()->for($automation)->create([
            'contact_id' => $completedContact->id,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)->get(route('visual-automations.contacts.index', $automation));

        $response->assertInertia(function (Assert $page): void {
            $page
                ->where('stats.contacts', 3)
                ->where('stats.active', 2)
                ->where('stats.completed', 1);
        });
    }

    public function test_stats_count_the_converted_and_the_exited_contacts(): void
    {
        $user = User::factory()->create();
        $automation = $this->createAutomationWithWorkflow($user);

        VisualAutomationEnrollment::factory()->for($automation)->create();
        VisualAutomationEnrollment::factory()->for($automation)->completed()->create();
        VisualAutomationEnrollment::factory()->for($automation)->completed()->create(['exit_reason' => EnrollmentExitReason::Goal]);
        VisualAutomationEnrollment::factory()->for($automation)->completed()->create(['exit_reason' => EnrollmentExitReason::Goal]);
        VisualAutomationEnrollment::factory()->for($automation)->completed()->create(['exit_reason' => EnrollmentExitReason::ExitCondition]);
        VisualAutomationEnrollment::factory()->for($automation)->completed()->create(['exit_reason' => EnrollmentExitReason::Unsubscribed]);

        $response = $this->actingAs($user)->get(route('visual-automations.contacts.index', $automation));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('stats.contacts', 6)
            ->where('stats.active', 1)
            ->where('stats.completed', 5)
            ->where('stats.converted', 2)
            ->where('stats.exited', 1));
    }

    public function test_contacts_index_filters_by_converted_and_by_exited(): void
    {
        $user = User::factory()->create();
        $automation = $this->createAutomationWithWorkflow($user);

        VisualAutomationEnrollment::factory()->for($automation)->completed()->create();
        $converted = VisualAutomationEnrollment::factory()->for($automation)->completed()->create(['exit_reason' => EnrollmentExitReason::Goal]);
        $exited = VisualAutomationEnrollment::factory()->for($automation)->completed()->create(['exit_reason' => EnrollmentExitReason::ExitCondition]);

        foreach (['converted' => $converted, 'exited' => $exited] as $filter => $enrollment) {
            $response = $this->actingAs($user)->get(route('visual-automations.contacts.index', [$automation, 'filter' => $filter]));

            $response->assertInertia(fn (Assert $page) => $page
                ->where('filter', $filter)
                ->has('contacts.data', 1)
                ->where('contacts.data.0.id', $enrollment->contact_id));
        }
    }
}
