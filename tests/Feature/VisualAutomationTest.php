<?php

namespace Tests\Feature;

use App\Models\MailList;
use App\Models\Sequence;
use App\Models\Tag;
use App\Models\User;
use App\Models\VisualAutomation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VisualAutomationTest extends TestCase
{
    use RefreshDatabase;

    private function validWorkflow(?int $listId = null, ?int $tagId = null): array
    {
        return [
            'nodes' => [
                [
                    'id' => 'trigger-1',
                    'type' => 'trigger',
                    'position' => ['x' => 0, 'y' => 0],
                    'data' => ['trigger_type' => 'contact_added_to_list', 'value' => $listId ?? 1],
                ],
                [
                    'id' => 'action-1',
                    'type' => 'action',
                    'position' => ['x' => 0, 'y' => 280],
                    'data' => ['action_type' => 'add_tag', 'value' => $tagId ?? 1],
                ],
            ],
            'edges' => [
                ['id' => 'edge-1', 'source' => 'trigger-1', 'target' => 'action-1'],
            ],
        ];
    }

    public function test_index_displays_users_visual_automations(): void
    {
        $user = User::factory()->create();
        $older = VisualAutomation::factory()->for($user)->create(['name' => 'Older']);
        $newer = VisualAutomation::factory()->for($user)->create(['name' => 'Newer']);
        VisualAutomation::factory()->create(); // other user

        $response = $this->actingAs($user)->get(route('visual-automations.index'));

        $response->assertInertia(function (Assert $page) use ($newer, $older): void {
            $page
                ->component('visual-automations/Index')
                ->has('automations', 2)
                ->where('automations.0.id', $newer->id)
                ->where('automations.1.id', $older->id);
        });
    }

    public function test_create_page_renders_with_catalogs(): void
    {
        $user = User::factory()->create();
        MailList::factory()->for($user)->create(['name' => 'List A']);
        Tag::factory()->for($user)->create(['name' => 'Tag A']);
        Sequence::factory()->for($user)->create(['name' => 'Seq A']);

        $response = $this->actingAs($user)->get(route('visual-automations.create'));

        $response->assertInertia(function (Assert $page): void {
            $page
                ->component('visual-automations/Show')
                ->where('mode', 'create')
                ->where('automation', null)
                ->has('lists', 1)
                ->has('tags', 1)
                ->has('sequences', 1);
        });
    }

    public function test_user_can_store_visual_automation(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create();

        $response = $this->actingAs($user)->post(route('visual-automations.store'), [
            'name' => 'My Workflow',
            'workflow' => $this->validWorkflow($list->id, $tag->id),
        ]);

        $automation = VisualAutomation::first();
        $response->assertRedirect(route('visual-automations.show', $automation));

        $this->assertDatabaseHas('visual_automations', [
            'user_id' => $user->id,
            'name' => 'My Workflow',
            'status' => 'draft',
        ]);
    }

    public function test_store_requires_name_and_workflow(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->from(route('visual-automations.create'))
            ->post(route('visual-automations.store'), []);

        $response->assertSessionHasErrors(['name', 'workflow']);
    }

    public function test_store_requires_exactly_one_trigger_node(): void
    {
        $user = User::factory()->create();

        // No trigger
        $response = $this->actingAs($user)
            ->from(route('visual-automations.create'))
            ->post(route('visual-automations.store'), [
                'name' => 'No Trigger',
                'workflow' => [
                    'nodes' => [
                        [
                            'id' => 'action-1',
                            'type' => 'action',
                            'position' => ['x' => 0, 'y' => 0],
                            'data' => [],
                        ],
                    ],
                    'edges' => [],
                ],
            ]);

        $response->assertSessionHasErrors('workflow.nodes');
    }

    public function test_store_rejects_workflow_with_two_triggers(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->from(route('visual-automations.create'))
            ->post(route('visual-automations.store'), [
                'name' => 'Two Triggers',
                'workflow' => [
                    'nodes' => [
                        [
                            'id' => 'trigger-1',
                            'type' => 'trigger',
                            'position' => ['x' => 0, 'y' => 0],
                            'data' => [],
                        ],
                        [
                            'id' => 'trigger-2',
                            'type' => 'trigger',
                            'position' => ['x' => 100, 'y' => 0],
                            'data' => [],
                        ],
                    ],
                    'edges' => [],
                ],
            ]);

        $response->assertSessionHasErrors('workflow.nodes');
    }

    public function test_show_page_renders_with_automation_data(): void
    {
        $user = User::factory()->create();
        MailList::factory()->for($user)->create();

        $automation = VisualAutomation::factory()->for($user)->create([
            'name' => 'Test Flow',
            'workflow' => $this->validWorkflow(),
        ]);

        $response = $this->actingAs($user)->get(route('visual-automations.show', $automation));

        $response->assertInertia(function (Assert $page) use ($automation): void {
            $page
                ->component('visual-automations/Show')
                ->where('mode', 'edit')
                ->where('automation.id', $automation->id)
                ->where('automation.name', 'Test Flow')
                ->has('automation.workflow.nodes', 2)
                ->has('automation.workflow.edges', 1)
                ->has('lists', 1);
        });
    }

    public function test_user_can_update_visual_automation(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create();
        $automation = VisualAutomation::factory()->for($user)->create([
            'name' => 'Old Name',
        ]);

        $newWorkflow = $this->validWorkflow($list->id, $tag->id);
        $newWorkflow['nodes'][] = [
            'id' => 'delay-1',
            'type' => 'delay',
            'position' => ['x' => 0, 'y' => 560],
            'data' => ['duration' => 3, 'unit' => 'days'],
        ];
        $newWorkflow['edges'][] = [
            'id' => 'edge-2',
            'source' => 'action-1',
            'target' => 'delay-1',
        ];

        $response = $this->actingAs($user)->put(route('visual-automations.update', $automation), [
            'name' => 'Updated Name',
            'workflow' => $newWorkflow,
        ]);

        $response->assertRedirect();

        $automation->refresh();
        $this->assertSame('Updated Name', $automation->name);
        $this->assertCount(3, $automation->workflow['nodes']);
        $this->assertCount(2, $automation->workflow['edges']);
    }

    public function test_user_can_toggle_status_to_live(): void
    {
        $user = User::factory()->create();
        $automation = VisualAutomation::factory()->for($user)->create(['status' => 'draft']);

        $response = $this->actingAs($user)->patch(route('visual-automations.status.update', $automation), [
            'status' => 'live',
        ]);

        $response->assertRedirect();
        $this->assertSame('live', $automation->fresh()->status);
    }

    public function test_user_can_toggle_status_to_draft(): void
    {
        $user = User::factory()->create();
        $automation = VisualAutomation::factory()->for($user)->live()->create();

        $response = $this->actingAs($user)->patch(route('visual-automations.status.update', $automation), [
            'status' => 'draft',
        ]);

        $response->assertRedirect();
        $this->assertSame('draft', $automation->fresh()->status);
    }

    public function test_status_update_validates_status_value(): void
    {
        $user = User::factory()->create();
        $automation = VisualAutomation::factory()->for($user)->create();

        $response = $this->actingAs($user)->patch(route('visual-automations.status.update', $automation), [
            'status' => 'invalid',
        ]);

        $response->assertSessionHasErrors('status');
    }

    public function test_user_can_delete_visual_automation(): void
    {
        $user = User::factory()->create();
        $automation = VisualAutomation::factory()->for($user)->create();

        $response = $this->actingAs($user)->delete(route('visual-automations.destroy', $automation));

        $response->assertRedirect(route('visual-automations.index'));
        $this->assertDatabaseMissing('visual_automations', ['id' => $automation->id]);
    }

    public function test_only_owner_can_view_visual_automation(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $automation = VisualAutomation::factory()->for($owner)->create();

        $response = $this->actingAs($otherUser)->get(route('visual-automations.show', $automation));

        $response->assertNotFound();
    }

    public function test_only_owner_can_update_visual_automation(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $list = MailList::factory()->for($otherUser)->create();
        $tag = Tag::factory()->for($otherUser)->create();
        $automation = VisualAutomation::factory()->for($owner)->create(['name' => 'Original']);

        $response = $this->actingAs($otherUser)->put(route('visual-automations.update', $automation), [
            'name' => 'Hacked',
            'workflow' => $this->validWorkflow($list->id, $tag->id),
        ]);

        $response->assertNotFound();
        $this->assertSame('Original', $automation->fresh()->name);
    }

    public function test_only_owner_can_delete_visual_automation(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $automation = VisualAutomation::factory()->for($owner)->create();

        $response = $this->actingAs($otherUser)->delete(route('visual-automations.destroy', $automation));

        $response->assertNotFound();
        $this->assertDatabaseHas('visual_automations', ['id' => $automation->id]);
    }

    public function test_store_validates_workflow_node_types(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->from(route('visual-automations.create'))
            ->post(route('visual-automations.store'), [
                'name' => 'Bad Node Type',
                'workflow' => [
                    'nodes' => [
                        [
                            'id' => 'trigger-1',
                            'type' => 'invalid-type',
                            'position' => ['x' => 0, 'y' => 0],
                            'data' => [],
                        ],
                    ],
                    'edges' => [],
                ],
            ]);

        $response->assertSessionHasErrors('workflow.nodes.0.type');
    }

    public function test_workflow_with_branching_condition_can_be_stored(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $tag1 = Tag::factory()->for($user)->create();
        $tag2 = Tag::factory()->for($user)->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);

        $workflow = [
            'nodes' => [
                [
                    'id' => 'trigger-1',
                    'type' => 'trigger',
                    'position' => ['x' => 0, 'y' => 0],
                    'data' => ['trigger_type' => 'contact_added_to_list', 'value' => $list->id],
                ],
                [
                    'id' => 'condition-1',
                    'type' => 'condition',
                    'position' => ['x' => 0, 'y' => 280],
                    'data' => ['condition_type' => 'has_tag', 'value' => $tag1->id],
                ],
                [
                    'id' => 'action-yes',
                    'type' => 'action',
                    'position' => ['x' => -200, 'y' => 560],
                    'data' => ['action_type' => 'add_sequence', 'value' => $sequence->id],
                ],
                [
                    'id' => 'action-no',
                    'type' => 'action',
                    'position' => ['x' => 200, 'y' => 560],
                    'data' => ['action_type' => 'add_tag', 'value' => $tag2->id],
                ],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'condition-1'],
                ['id' => 'e2', 'source' => 'condition-1', 'target' => 'action-yes', 'sourceHandle' => 'yes'],
                ['id' => 'e3', 'source' => 'condition-1', 'target' => 'action-no', 'sourceHandle' => 'no'],
            ],
        ];

        $response = $this->actingAs($user)->post(route('visual-automations.store'), [
            'name' => 'Branching Flow',
            'workflow' => $workflow,
        ]);

        $automation = VisualAutomation::first();
        $response->assertRedirect(route('visual-automations.show', $automation));
        $this->assertCount(4, $automation->workflow['nodes']);
        $this->assertCount(3, $automation->workflow['edges']);

        // Verify model helper methods work
        $this->assertNotNull($automation->triggerNode());
        $this->assertSame('trigger', $automation->triggerNode()['type']);
        $this->assertCount(1, $automation->outgoingEdges('condition-1', 'yes'));
        $this->assertCount(1, $automation->outgoingEdges('condition-1', 'no'));
        $this->assertNotNull($automation->findNode('action-yes'));
        $this->assertNull($automation->findNode('nonexistent'));
    }

    public function test_store_rejects_workflow_referencing_other_users_tag(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $otherTag = Tag::factory()->for($otherUser)->create();

        $response = $this->actingAs($user)
            ->from(route('visual-automations.create'))
            ->post(route('visual-automations.store'), [
                'name' => 'Bad Tag',
                'workflow' => $this->validWorkflow($list->id, $otherTag->id),
            ]);

        $response->assertSessionHasErrors('workflow.nodes');
        $this->assertDatabaseMissing('visual_automations', ['name' => 'Bad Tag']);
    }

    public function test_store_rejects_workflow_referencing_other_users_list(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $tag = Tag::factory()->for($user)->create();
        $otherList = MailList::factory()->for($otherUser)->create();

        $response = $this->actingAs($user)
            ->from(route('visual-automations.create'))
            ->post(route('visual-automations.store'), [
                'name' => 'Bad List',
                'workflow' => $this->validWorkflow($otherList->id, $tag->id),
            ]);

        $response->assertSessionHasErrors('workflow.nodes');
        $this->assertDatabaseMissing('visual_automations', ['name' => 'Bad List']);
    }

    public function test_store_rejects_workflow_referencing_other_users_sequence(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $otherSequence = Sequence::factory()->for($otherUser)->create();

        $response = $this->actingAs($user)
            ->from(route('visual-automations.create'))
            ->post(route('visual-automations.store'), [
                'name' => 'Bad Sequence',
                'workflow' => [
                    'nodes' => [
                        [
                            'id' => 'trigger-1',
                            'type' => 'trigger',
                            'position' => ['x' => 0, 'y' => 0],
                            'data' => ['trigger_type' => 'contact_added_to_list', 'value' => $list->id],
                        ],
                        [
                            'id' => 'action-1',
                            'type' => 'action',
                            'position' => ['x' => 0, 'y' => 280],
                            'data' => ['action_type' => 'add_sequence', 'value' => $otherSequence->id],
                        ],
                    ],
                    'edges' => [
                        ['id' => 'edge-1', 'source' => 'trigger-1', 'target' => 'action-1'],
                    ],
                ],
            ]);

        $response->assertSessionHasErrors('workflow.nodes');
        $this->assertDatabaseMissing('visual_automations', ['name' => 'Bad Sequence']);
    }

    public function test_store_rejects_add_sequence_action_referencing_non_live_sequence(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'draft']);

        $response = $this->actingAs($user)
            ->from(route('visual-automations.create'))
            ->post(route('visual-automations.store'), [
                'name' => 'Draft Sequence Workflow',
                'workflow' => [
                    'nodes' => [
                        [
                            'id' => 'trigger-1',
                            'type' => 'trigger',
                            'position' => ['x' => 0, 'y' => 0],
                            'data' => ['trigger_type' => 'contact_added_to_list', 'value' => $list->id],
                        ],
                        [
                            'id' => 'action-1',
                            'type' => 'action',
                            'position' => ['x' => 0, 'y' => 280],
                            'data' => ['action_type' => 'add_sequence', 'value' => $sequence->id],
                        ],
                    ],
                    'edges' => [
                        ['id' => 'edge-1', 'source' => 'trigger-1', 'target' => 'action-1'],
                    ],
                ],
            ]);

        $response->assertSessionHasErrors('workflow.nodes');
        $this->assertDatabaseMissing('visual_automations', ['name' => 'Draft Sequence Workflow']);
    }

    public function test_cannot_activate_visual_automation_when_add_sequence_targets_non_live_sequence(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'draft']);

        $automation = VisualAutomation::factory()
            ->for($user)
            ->create([
                'status' => VisualAutomation::STATUS_DRAFT,
                'workflow' => [
                    'nodes' => [
                        [
                            'id' => 'trigger-1',
                            'type' => 'trigger',
                            'position' => ['x' => 0, 'y' => 0],
                            'data' => ['trigger_type' => 'contact_added_to_list', 'value' => $list->id],
                        ],
                        [
                            'id' => 'action-1',
                            'type' => 'action',
                            'position' => ['x' => 0, 'y' => 280],
                            'data' => ['action_type' => 'add_sequence', 'value' => $sequence->id],
                        ],
                    ],
                    'edges' => [
                        ['id' => 'edge-1', 'source' => 'trigger-1', 'target' => 'action-1'],
                    ],
                ],
            ]);

        $response = $this->actingAs($user)->patch(route('visual-automations.status.update', $automation), [
            'status' => VisualAutomation::STATUS_LIVE,
        ]);

        $response->assertSessionHasErrors('status');
        $automation->refresh();
        $this->assertSame(VisualAutomation::STATUS_DRAFT, $automation->status);
    }

    public function test_store_rejects_edges_referencing_nonexistent_nodes(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create();

        $response = $this->actingAs($user)
            ->from(route('visual-automations.create'))
            ->post(route('visual-automations.store'), [
                'name' => 'Bad Edges',
                'workflow' => [
                    'nodes' => [
                        [
                            'id' => 'trigger-1',
                            'type' => 'trigger',
                            'position' => ['x' => 0, 'y' => 0],
                            'data' => ['trigger_type' => 'contact_added_to_list', 'value' => $list->id],
                        ],
                    ],
                    'edges' => [
                        ['id' => 'edge-1', 'source' => 'trigger-1', 'target' => 'nonexistent-node'],
                    ],
                ],
            ]);

        $response->assertSessionHasErrors('workflow.edges.0.target');
    }

    public function test_store_rejects_invalid_action_type(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();

        $response = $this->actingAs($user)
            ->from(route('visual-automations.create'))
            ->post(route('visual-automations.store'), [
                'name' => 'Bad Action',
                'workflow' => [
                    'nodes' => [
                        [
                            'id' => 'trigger-1',
                            'type' => 'trigger',
                            'position' => ['x' => 0, 'y' => 0],
                            'data' => ['trigger_type' => 'contact_added_to_list', 'value' => $list->id],
                        ],
                        [
                            'id' => 'action-1',
                            'type' => 'action',
                            'position' => ['x' => 0, 'y' => 280],
                            'data' => ['action_type' => 'hack_system', 'value' => 1],
                        ],
                    ],
                    'edges' => [
                        ['id' => 'edge-1', 'source' => 'trigger-1', 'target' => 'action-1'],
                    ],
                ],
            ]);

        $response->assertSessionHasErrors('workflow.nodes.1.data.action_type');
    }

    public function test_store_rejects_invalid_trigger_type(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->from(route('visual-automations.create'))
            ->post(route('visual-automations.store'), [
                'name' => 'Bad Trigger',
                'workflow' => [
                    'nodes' => [
                        [
                            'id' => 'trigger-1',
                            'type' => 'trigger',
                            'position' => ['x' => 0, 'y' => 0],
                            'data' => ['trigger_type' => 'invalid_trigger'],
                        ],
                    ],
                    'edges' => [],
                ],
            ]);

        $response->assertSessionHasErrors('workflow.nodes.0.data.trigger_type');
    }

    public function test_store_rejects_invalid_condition_type(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();

        $response = $this->actingAs($user)
            ->from(route('visual-automations.create'))
            ->post(route('visual-automations.store'), [
                'name' => 'Bad Condition',
                'workflow' => [
                    'nodes' => [
                        [
                            'id' => 'trigger-1',
                            'type' => 'trigger',
                            'position' => ['x' => 0, 'y' => 0],
                            'data' => ['trigger_type' => 'contact_added_to_list', 'value' => $list->id],
                        ],
                        [
                            'id' => 'cond-1',
                            'type' => 'condition',
                            'position' => ['x' => 0, 'y' => 280],
                            'data' => ['condition_type' => 'bogus_condition', 'value' => 1],
                        ],
                    ],
                    'edges' => [
                        ['id' => 'edge-1', 'source' => 'trigger-1', 'target' => 'cond-1'],
                    ],
                ],
            ]);

        $response->assertSessionHasErrors('workflow.nodes.1.data.condition_type');
    }

    public function test_store_rejects_invalid_delay_unit(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();

        $response = $this->actingAs($user)
            ->from(route('visual-automations.create'))
            ->post(route('visual-automations.store'), [
                'name' => 'Bad Delay',
                'workflow' => [
                    'nodes' => [
                        [
                            'id' => 'trigger-1',
                            'type' => 'trigger',
                            'position' => ['x' => 0, 'y' => 0],
                            'data' => ['trigger_type' => 'contact_added_to_list', 'value' => $list->id],
                        ],
                        [
                            'id' => 'delay-1',
                            'type' => 'delay',
                            'position' => ['x' => 0, 'y' => 280],
                            'data' => ['duration' => 1, 'unit' => 'weeks'],
                        ],
                    ],
                    'edges' => [
                        ['id' => 'edge-1', 'source' => 'trigger-1', 'target' => 'delay-1'],
                    ],
                ],
            ]);

        $response->assertSessionHasErrors('workflow.nodes.1.data.unit');
    }

    public function test_store_rejects_zero_duration(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();

        $response = $this->actingAs($user)
            ->from(route('visual-automations.create'))
            ->post(route('visual-automations.store'), [
                'name' => 'Zero Duration',
                'workflow' => [
                    'nodes' => [
                        [
                            'id' => 'trigger-1',
                            'type' => 'trigger',
                            'position' => ['x' => 0, 'y' => 0],
                            'data' => ['trigger_type' => 'contact_added_to_list', 'value' => $list->id],
                        ],
                        [
                            'id' => 'delay-1',
                            'type' => 'delay',
                            'position' => ['x' => 0, 'y' => 280],
                            'data' => ['duration' => 0, 'unit' => 'days'],
                        ],
                    ],
                    'edges' => [
                        ['id' => 'edge-1', 'source' => 'trigger-1', 'target' => 'delay-1'],
                    ],
                ],
            ]);

        $response->assertSessionHasErrors('workflow.nodes.1.data.duration');
    }

    public function test_index_includes_enrollments_count(): void
    {
        $user = User::factory()->create();
        $automation = VisualAutomation::factory()->for($user)->create();

        $response = $this->actingAs($user)->get(route('visual-automations.index'));

        $response->assertInertia(function (Assert $page): void {
            $page
                ->component('visual-automations/Index')
                ->has('automations', 1)
                ->where('automations.0.enrollments_count', 0);
        });
    }
}
