<?php

namespace Tests\Feature;

use App\Models\Automation;
use App\Models\Contact;
use App\Models\ContactAttribute;
use App\Models\MailList;
use App\Models\Sequence;
use App\Models\Tag;
use App\Models\User;
use App\Support\Tenant;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_page_includes_store_route(): void
    {
        $user = User::factory()->create();
        MailList::factory()->for($user)->create(['name' => 'List A']);
        Tag::factory()->for($user)->create(['name' => 'Tag A']);
        Sequence::factory()->for($user)->create(['name' => 'Sequence A']);

        $response = $this->actingAs($user)->get(route('automations.create'));

        $response->assertInertia(function (Assert $page): void {
            $page
                ->component('automations/Create')
                ->where('storeRoute', route('automations.store'))
                ->has('lists', 1)
                ->has('tags', 1)
                ->has('sequences', 1);
        });
    }

    public function test_index_only_shows_authenticated_users_automations_in_descending_order(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create(['name' => 'Alpha List']);
        $tag = Tag::factory()->for($user)->create(['name' => 'Beta Tag']);
        $sequence = Sequence::factory()->for($user)->create(['name' => 'Alpha Sequence']);
        $older = Automation::factory()->for($user)->create(['name' => 'Older Automation']);
        $newer = Automation::factory()->for($user)->create(['name' => 'Newer Automation']);
        Automation::factory()->create(); // Other user's automation should not appear

        $response = $this->actingAs($user)->get(route('automations.index'));

        $response->assertInertia(function (Assert $page) use ($older, $newer, $list, $tag, $sequence): void {
            $page
                ->component('automations/Index')
                ->has('automations', 2)
                ->where('automations.0.id', $newer->id)
                ->where('automations.1.id', $older->id)
                ->where('automations.0.name', 'Newer Automation')
                ->where('automations.1.name', 'Older Automation')
                ->has('catalogs.lists', 1)
                ->where('catalogs.lists.0.id', $list->id)
                ->where('catalogs.lists.0.name', 'Alpha List')
                ->has('catalogs.tags', 1)
                ->where('catalogs.tags.0.id', $tag->id)
                ->where('catalogs.tags.0.name', 'Beta Tag')
                ->has('catalogs.sequences', 1)
                ->where('catalogs.sequences.0.id', $sequence->id)
                ->where('catalogs.sequences.0.name', 'Alpha Sequence');
        });
    }

    public function test_create_page_lists_tags_and_sequences_are_sorted_and_scoped_to_user(): void
    {
        $user = User::factory()->create();

        $alphaList = MailList::factory()->for($user)->create(['name' => 'Alpha List']);
        $betaList = MailList::factory()->for($user)->create(['name' => 'Beta List']);
        MailList::factory()->create(['name' => 'Other List']); // Should not be visible

        $alphaTag = Tag::factory()->for($user)->create(['name' => 'Alpha Tag']);
        $betaTag = Tag::factory()->for($user)->create(['name' => 'Beta Tag']);
        Tag::factory()->create(['name' => 'Other Tag']); // Should not be visible

        $alphaSequence = Sequence::factory()->for($user)->create(['name' => 'Alpha Sequence']);
        $betaSequence = Sequence::factory()->for($user)->create(['name' => 'Beta Sequence']);
        Sequence::factory()->create(['name' => 'Other Sequence']); // Should not be visible

        $response = $this->actingAs($user)->get(route('automations.create'));

        $response->assertInertia(function (Assert $page) use ($alphaList, $betaList, $alphaTag, $betaTag, $alphaSequence, $betaSequence): void {
            $page
                ->component('automations/Create')
                ->has('lists', 2)
                ->where('lists.0.id', $alphaList->id)
                ->where('lists.1.id', $betaList->id)
                ->where('lists.0.name', 'Alpha List')
                ->where('lists.1.name', 'Beta List')
                ->has('tags', 2)
                ->where('tags.0.id', $alphaTag->id)
                ->where('tags.1.id', $betaTag->id)
                ->where('tags.0.name', 'Alpha Tag')
                ->where('tags.1.name', 'Beta Tag')
                ->has('sequences', 2)
                ->where('sequences.0.id', $alphaSequence->id)
                ->where('sequences.1.id', $betaSequence->id)
                ->where('sequences.0.name', 'Alpha Sequence')
                ->where('sequences.1.name', 'Beta Sequence');
        });
    }

    public function test_store_rejects_condition_and_actions_that_reference_other_users_data(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $foreignList = MailList::factory()->for($otherUser)->create();
        $foreignTag = Tag::factory()->for($otherUser)->create();

        $foreignSequence = Sequence::factory()->for($otherUser)->create();

        $response = $this->actingAs($user)
            ->from(route('automations.create'))
            ->post(route('automations.store'), [
                'name' => 'Invalid Automation',
                'type' => 'simple',
                'condition' => [
                    'type' => 'contact_added_to_list',
                    'value' => $foreignList->id,
                ],
                'actions' => [
                    ['type' => 'add_tag', 'value' => $foreignTag->id],
                    ['type' => 'add_sequence', 'value' => $foreignSequence->id],
                ],
            ]);

        $response->assertRedirect(route('automations.create'));
        $response->assertSessionHasErrors([
            'condition.value' => 'Selected list is invalid.',
            'actions.0.value' => 'Tag action value is invalid.',
            'actions.1.value' => 'Sequence action value is invalid.',
        ]);

        $this->assertDatabaseMissing('automations', [
            'name' => 'Invalid Automation',
            'user_id' => $user->id,
        ]);
    }

    public function test_edit_page_displays_existing_automation_details(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create(['name' => 'Main List']);
        $tag = Tag::factory()->for($user)->create(['name' => 'VIP']);

        $automation = Automation::factory()
            ->for($user)
            ->create([
                'name' => 'Welcome Flow',
                'condition' => ['type' => 'contact_added_to_list', 'value' => $list->id],
                'actions' => [['type' => 'add_tag', 'value' => $tag->id]],
            ]);

        $response = $this->actingAs($user)->get(route('automations.edit', $automation));

        $response->assertInertia(function (Assert $page) use ($automation): void {
            $page
                ->component('automations/Edit')
                ->where('automation.id', $automation->id)
                ->where('automation.name', 'Welcome Flow')
                ->has('lists')
                ->has('tags')
                ->where('updateRoute', route('automations.update', $automation));
        });
    }

    public function test_user_can_update_automation(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $newList = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create();

        $automation = Automation::factory()
            ->for($user)
            ->create([
                'name' => 'Old Name',
                'condition' => ['type' => 'contact_added_to_list', 'value' => $list->id],
                'actions' => [['type' => 'add_tag', 'value' => $tag->id]],
            ]);

        $response = $this->actingAs($user)->put(route('automations.update', $automation), [
            'name' => 'Updated Name',
            'type' => 'simple',
            'condition' => [
                'type' => 'contact_added_to_list',
                'value' => $newList->id,
            ],
            'actions' => [
                ['type' => 'remove_tag', 'value' => $tag->id],
            ],
        ]);

        $response->assertRedirect(route('automations.index'));

        $automation->refresh();
        $this->assertSame('Updated Name', $automation->name);
        $this->assertSame($newList->id, $automation->condition['value']);
        $this->assertSame('remove_tag', $automation->actions[0]['type']);
        $this->assertSame($tag->id, $automation->actions[0]['value']);
    }

    public function test_contact_observer_runs_matching_automation_when_added_to_list(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create();

        Automation::factory()
            ->for($user)
            ->create([
                'type' => 'simple',
                'condition' => ['type' => 'contact_added_to_list', 'value' => $list->id],
                'actions' => [
                    ['type' => 'add_tag', 'value' => $tag->id],
                ],
            ]);

        $contact = Contact::factory()->for($user)->create();

        $contact->syncLists([$list->id], true);

        $contact->refresh();
        $this->assertTrue($contact->tags()->where('tags.id', $tag->id)->exists());
    }

    public function test_store_requires_action_values_and_returns_validation_errors(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();

        $response = $this->actingAs($user)
            ->from(route('automations.create'))
            ->post(route('automations.store'), [
                'name' => 'Missing Action Value',
                'type' => 'simple',
                'condition' => [
                    'type' => 'contact_added_to_list',
                    'value' => $list->id,
                ],
                'actions' => [
                    ['type' => 'add_tag'],
                    ['type' => 'add_list', 'value' => null],
                ],
            ]);

        $response->assertRedirect(route('automations.create'));
        $response->assertSessionHasErrors([
            'actions.0.value' => 'Action value is required.',
            'actions.1.value' => 'Action value is required.',
        ]);

        $this->assertDatabaseCount('automations', 0);
    }

    public function test_user_can_create_simple_automation(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create();

        $response = $this->actingAs($user)->post(route('automations.store'), [
            'name' => 'Welcome Tag',
            'type' => 'simple',
            'condition' => [
                'type' => 'contact_added_to_list',
                'value' => $list->id,
            ],
            'actions' => [
                ['type' => 'add_tag', 'value' => $tag->id],
                ['type' => 'remove_list', 'value' => $list->id],
            ],
        ]);

        $response->assertRedirect(route('automations.index'));

        $this->assertDatabaseHas('automations', [
            'user_id' => $user->id,
            'name' => 'Welcome Tag',
            'type' => 'simple',
        ]);
    }

    /**
     * @param  Closure(User): MailList|Tag|Sequence  $valueFactory
     */
    #[DataProvider('newConditionProvider')]
    public function test_user_can_create_automation_with_new_condition_triggers(string $conditionType, Closure $valueFactory): void
    {
        $user = User::factory()->create();
        $triggerTarget = $valueFactory($user);
        $tag = Tag::factory()->for($user)->create();

        $response = $this->actingAs($user)->post(route('automations.store'), [
            'name' => 'Expanded Trigger',
            'type' => 'simple',
            'condition' => [
                'type' => $conditionType,
                'value' => $triggerTarget->id,
            ],
            'actions' => [
                ['type' => 'add_tag', 'value' => $tag->id],
            ],
        ]);

        $response->assertRedirect(route('automations.index'));

        $this->assertDatabaseHas('automations', [
            'user_id' => $user->id,
            'name' => 'Expanded Trigger',
            'condition->type' => $conditionType,
            'condition->value' => $triggerTarget->id,
        ]);
    }

    /**
     * @return array<string, array{string, Closure(User): MailList|Tag|Sequence}>
     */
    public static function newConditionProvider(): array
    {
        return [
            'removed from list' => [
                'removed_from_list',
                fn (User $user): MailList => MailList::factory()->for($user)->create(),
            ],
            'removed tag' => [
                'removed_tag',
                fn (User $user): Tag => Tag::factory()->for($user)->create(),
            ],
            'sequence finished' => [
                'sequence_finished',
                fn (User $user): Sequence => Sequence::factory()->for($user)->create(),
            ],
            'added to sequence' => [
                'added_to_sequence',
                fn (User $user): Sequence => Sequence::factory()->for($user)->create(),
            ],
        ];
    }

    public function test_automation_runs_when_contact_added_to_list(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create();

        $automation = Automation::factory()
            ->for($user)
            ->create([
                'type' => 'simple',
                'condition' => ['type' => 'contact_added_to_list', 'value' => $list->id],
                'actions' => [
                    ['type' => 'add_tag', 'value' => $tag->id],
                    ['type' => 'remove_list', 'value' => $list->id],
                ],
            ]);

        $contact = Contact::factory()->for($user)->create();

        $contact->syncLists([$list->id], true);

        $contact->refresh();
        $this->assertTrue($contact->tags->contains('id', $tag->id));
        $this->assertFalse($contact->lists->contains('id', $list->id));
    }

    public function test_automation_run_for_tag_condition_only_affects_contacts_for_the_automation_owner(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();
        $triggerTag = Tag::factory()->for($user)->create();

        $automation = Automation::factory()
            ->for($user)
            ->create([
                'type' => 'simple',
                'condition' => ['type' => 'tag_added', 'value' => $triggerTag->id],
                'actions' => [
                    ['type' => 'add_list', 'value' => $list->id],
                    ['type' => 'remove_tag', 'value' => $triggerTag->id],
                ],
            ]);

        $contact = Contact::factory()->for($user)->create();
        $contact->syncTags([$triggerTag->id], true);

        $otherContact = Contact::factory()->for(User::factory()->create())->create();
        Tenant::run($otherContact->client_id, fn () => $otherContact->syncTags([$triggerTag->id], true));

        $contact->refresh();
        $this->assertTrue($contact->lists->contains('id', $list->id));
        $this->assertFalse($contact->tags->contains('id', $triggerTag->id));

        $otherContact->refresh();
        $this->assertFalse($otherContact->lists->contains('id', $list->id));
        $this->assertTrue($otherContact->tags->contains('id', $triggerTag->id));
    }

    public function test_run_skips_actions_without_values_or_with_unknown_types(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();
        $anotherList = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create();

        $automation = Automation::factory()
            ->for($user)
            ->create([
                'type' => 'simple',
                'condition' => ['type' => 'contact_added_to_list', 'value' => $list->id],
                'actions' => [
                    ['type' => 'add_tag', 'value' => null],
                    ['type' => 'unknown_action', 'value' => $anotherList->id],
                ],
            ]);

        $contact = Contact::factory()->for($user)->create();
        $contact->syncLists([$list->id], true);

        $contact->refresh();
        $this->assertFalse($contact->tags->contains('id', $tag->id));
        $this->assertFalse($contact->lists->contains('id', $anotherList->id));
        $this->assertTrue($contact->lists->contains('id', $list->id));
    }

    public function test_automation_can_add_contact_to_sequence(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);

        $automation = Automation::factory()
            ->for($user)
            ->create([
                'type' => 'simple',
                'condition' => ['type' => 'contact_added_to_list', 'value' => $list->id],
                'actions' => [
                    ['type' => 'add_sequence', 'value' => $sequence->id],
                ],
            ]);

        $contact = Contact::factory()->for($user)->create();
        $contact->syncLists([$list->id], true);

        $contact->refresh();
        $this->assertTrue($contact->sequences()->whereKey($sequence->id)->exists());
    }

    public function test_user_can_toggle_automation_status_from_live_to_paused(): void
    {
        $user = User::factory()->create();
        $automation = Automation::factory()->for($user)->create(['is_live' => true]);

        $response = $this->actingAs($user)->patch(route('automations.status.update', $automation), [
            'is_live' => false,
        ]);

        $response->assertRedirect();

        $automation->refresh();
        $this->assertFalse($automation->is_live);
    }

    public function test_user_can_toggle_automation_status_from_paused_to_live(): void
    {
        $user = User::factory()->create();
        $automation = Automation::factory()->for($user)->paused()->create();

        $response = $this->actingAs($user)->patch(route('automations.status.update', $automation), [
            'is_live' => true,
        ]);

        $response->assertRedirect();

        $automation->refresh();
        $this->assertTrue($automation->is_live);
    }

    public function test_only_owner_can_toggle_automation_status(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $automation = Automation::factory()->for($owner)->create(['is_live' => true]);

        $response = $this->actingAs($otherUser)->patch(route('automations.status.update', $automation), [
            'is_live' => false,
        ]);

        $response->assertNotFound();

        $automation->refresh();
        $this->assertTrue($automation->is_live);
    }

    public function test_sync_sequences_skips_non_live_sequences(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $liveSequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $draftSequence = Sequence::factory()->for($user)->create(['status' => 'draft']);

        $contact = Contact::factory()->for($user)->create();
        $contact->syncSequences([$liveSequence->id, $draftSequence->id], true);

        $this->assertTrue($contact->sequences()->whereKey($liveSequence->id)->exists());
        $this->assertFalse($contact->sequences()->whereKey($draftSequence->id)->exists());
    }

    public function test_store_rejects_add_sequence_action_referencing_non_live_sequence(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'draft']);

        $response = $this->actingAs($user)
            ->from(route('automations.create'))
            ->post(route('automations.store'), [
                'name' => 'Draft Sequence Automation',
                'type' => 'simple',
                'condition' => [
                    'type' => 'contact_added_to_list',
                    'value' => $list->id,
                ],
                'actions' => [
                    ['type' => 'add_sequence', 'value' => $sequence->id],
                ],
            ]);

        $response->assertRedirect(route('automations.create'));
        $response->assertSessionHasErrors(['actions.0.value']);
        $this->assertDatabaseMissing('automations', ['name' => 'Draft Sequence Automation']);
    }

    public function test_cannot_activate_automation_when_add_sequence_targets_non_live_sequence(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'draft']);

        $automation = Automation::factory()
            ->for($user)
            ->paused()
            ->create([
                'type' => 'simple',
                'condition' => ['type' => 'contact_added_to_list', 'value' => $list->id],
                'actions' => [
                    ['type' => 'add_sequence', 'value' => $sequence->id],
                ],
            ]);

        $response = $this->actingAs($user)->patch(route('automations.status.update', $automation), [
            'is_live' => true,
        ]);

        $response->assertSessionHasErrors('is_live');
        $automation->refresh();
        $this->assertFalse($automation->is_live);
    }

    public function test_user_can_delete_automation(): void
    {
        $user = User::factory()->create();
        $automation = Automation::factory()->for($user)->create();

        $response = $this->actingAs($user)->delete(route('automations.destroy', $automation));

        $response->assertRedirect(route('automations.index'));
        $this->assertDatabaseMissing('automations', ['id' => $automation->id]);
    }

    public function test_only_owner_can_delete_automation(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $automation = Automation::factory()->for($owner)->create();

        $response = $this->actingAs($otherUser)->delete(route('automations.destroy', $automation));

        $response->assertNotFound();
        $this->assertDatabaseHas('automations', ['id' => $automation->id]);
    }

    public function test_paused_automation_does_not_run_when_contact_added_to_list(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create();

        Automation::factory()
            ->for($user)
            ->paused()
            ->create([
                'type' => 'simple',
                'condition' => ['type' => 'contact_added_to_list', 'value' => $list->id],
                'actions' => [
                    ['type' => 'add_tag', 'value' => $tag->id],
                ],
            ]);

        $contact = Contact::factory()->for($user)->create();
        $contact->syncLists([$list->id], true);

        $contact->refresh();
        $this->assertFalse($contact->tags()->where('tags.id', $tag->id)->exists());
    }

    public function test_paused_automation_does_not_run_when_tag_added(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();
        $triggerTag = Tag::factory()->for($user)->create();

        Automation::factory()
            ->for($user)
            ->paused()
            ->create([
                'type' => 'simple',
                'condition' => ['type' => 'tag_added', 'value' => $triggerTag->id],
                'actions' => [
                    ['type' => 'add_list', 'value' => $list->id],
                ],
            ]);

        $contact = Contact::factory()->for($user)->create();
        $contact->syncTags([$triggerTag->id], true);

        $contact->refresh();
        $this->assertFalse($contact->lists()->where('lists.id', $list->id)->exists());
    }

    public function test_live_automation_still_runs_when_contact_added_to_list(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create();

        Automation::factory()
            ->for($user)
            ->create([
                'is_live' => true,
                'type' => 'simple',
                'condition' => ['type' => 'contact_added_to_list', 'value' => $list->id],
                'actions' => [
                    ['type' => 'add_tag', 'value' => $tag->id],
                ],
            ]);

        $contact = Contact::factory()->for($user)->create();
        $contact->syncLists([$list->id], true);

        $contact->refresh();
        $this->assertTrue($contact->tags()->where('tags.id', $tag->id)->exists());
    }

    public function test_edit_page_includes_destroy_and_status_routes(): void
    {
        $user = User::factory()->create();
        $automation = Automation::factory()->for($user)->create();

        $response = $this->actingAs($user)->get(route('automations.edit', $automation));

        $response->assertInertia(function (Assert $page) use ($automation): void {
            $page
                ->component('automations/Edit')
                ->where('automation.is_live', true)
                ->where('destroyRoute', route('automations.destroy', $automation))
                ->where('statusRoute', route('automations.status.update', $automation));
        });
    }

    public function test_index_includes_is_live_in_automation_data(): void
    {
        $user = User::factory()->create();
        Automation::factory()->for($user)->create(['is_live' => true]);
        Automation::factory()->for($user)->paused()->create();

        $response = $this->actingAs($user)->get(route('automations.index'));

        $response->assertInertia(function (Assert $page): void {
            $page
                ->component('automations/Index')
                ->has('automations', 2)
                ->where('automations.0.is_live', false)
                ->where('automations.1.is_live', true);
        });
    }

    public function test_status_update_validates_is_live_is_required(): void
    {
        $user = User::factory()->create();
        $automation = Automation::factory()->for($user)->create();

        $response = $this->actingAs($user)->patch(route('automations.status.update', $automation), []);

        $response->assertSessionHasErrors('is_live');
    }

    public function test_store_saves_attribute_changed_to_condition_with_exact_shape(): void
    {
        $user = User::factory()->create();
        ContactAttribute::factory()->for($user)->create(['name' => 'plan']);
        $tag = Tag::factory()->for($user)->create();

        $response = $this->actingAs($user)->post(route('automations.store'), $this->attributeAutomationPayload([
            'type' => 'attribute_changed',
            'attribute' => 'plan',
            'operator' => 'changed_to',
            'value' => '  pro  ',
            'extra' => 'dropped',
        ], $tag));

        $response->assertRedirect(route('automations.index'));
        $this->assertSame([
            'type' => 'attribute_changed',
            'attribute' => 'plan',
            'operator' => 'changed_to',
            'value' => 'pro',
        ], Automation::query()->sole()->condition);
    }

    public function test_store_saves_attribute_changed_condition_with_null_value(): void
    {
        $user = User::factory()->create();
        ContactAttribute::factory()->for($user)->create(['name' => 'plan']);
        $tag = Tag::factory()->for($user)->create();

        $response = $this->actingAs($user)->post(route('automations.store'), $this->attributeAutomationPayload([
            'type' => 'attribute_changed',
            'attribute' => 'plan',
            'operator' => 'changed',
            'value' => 'ignored',
            'extra' => 'dropped',
        ], $tag));

        $response->assertRedirect(route('automations.index'));
        $this->assertSame([
            'type' => 'attribute_changed',
            'attribute' => 'plan',
            'operator' => 'changed',
            'value' => null,
        ], Automation::query()->sole()->condition);
    }

    /**
     * @param  array<string, mixed>  $conditionOverrides
     */
    #[DataProvider('invalidAttributeConditionProvider')]
    public function test_store_rejects_invalid_attribute_changed_condition(array $conditionOverrides, string $errorKey): void
    {
        $user = User::factory()->create();
        ContactAttribute::factory()->for($user)->create(['name' => 'plan']);
        ContactAttribute::factory()->create(['name' => 'foreign']);
        $tag = Tag::factory()->for($user)->create();

        $condition = array_filter([
            'type' => 'attribute_changed',
            'attribute' => 'plan',
            'operator' => 'changed_to',
            'value' => 'pro',
            ...$conditionOverrides,
        ], fn (mixed $value): bool => $value !== null);

        $response = $this->actingAs($user)
            ->from(route('automations.create'))
            ->post(route('automations.store'), $this->attributeAutomationPayload($condition, $tag));

        $response->assertRedirect(route('automations.create'));
        $response->assertSessionHasErrors($errorKey);
        $this->assertDatabaseCount('automations', 0);
    }

    public function test_blank_attribute_value_error_uses_plain_words(): void
    {
        $user = User::factory()->create();
        ContactAttribute::factory()->for($user)->create(['name' => 'plan']);
        $tag = Tag::factory()->for($user)->create();

        $response = $this->actingAs($user)
            ->from(route('automations.create'))
            ->post(route('automations.store'), $this->attributeAutomationPayload([
                'type' => 'attribute_changed',
                'attribute' => 'plan',
                'operator' => 'changed_to',
                'value' => '',
            ], $tag));

        $response->assertSessionHasErrors(['condition.value' => 'Enter the value that the attribute changes to.']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidAttributeConditionProvider(): array
    {
        return [
            'missing operator' => [['operator' => null], 'condition.operator'],
            'unknown operator' => [['operator' => 'equals'], 'condition.operator'],
            'missing value' => [['value' => null], 'condition.value'],
            'blank value' => [['value' => '   '], 'condition.value'],
            'value too long' => [['value' => str_repeat('a', 256)], 'condition.value'],
            'attribute from another client' => [['attribute' => 'foreign'], 'condition.attribute'],
            'attribute that does not exist' => [['attribute' => 'missing'], 'condition.attribute'],
            'missing attribute' => [['attribute' => null], 'condition.attribute'],
        ];
    }

    public function test_store_accepts_attribute_created_by_teammate_in_same_client(): void
    {
        $user = User::factory()->create();
        $teammate = User::factory()->create(['client_id' => $user->client_id]);
        ContactAttribute::factory()->for($teammate)->create(['name' => 'plan']);
        $tag = Tag::factory()->for($user)->create();

        $response = $this->actingAs($user)->post(route('automations.store'), $this->attributeAutomationPayload([
            'type' => 'attribute_changed',
            'attribute' => 'plan',
            'operator' => 'changed',
        ], $tag));

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('automations.index'));
        $this->assertDatabaseCount('automations', 1);
    }

    public function test_update_switches_between_tag_added_and_attribute_changed(): void
    {
        $user = User::factory()->create();
        ContactAttribute::factory()->for($user)->create(['name' => 'plan']);
        $tag = Tag::factory()->for($user)->create();
        $automation = Automation::factory()->for($user)->create([
            'condition' => ['type' => 'tag_added', 'value' => $tag->id],
            'actions' => [['type' => 'add_tag', 'value' => $tag->id]],
        ]);

        $this->actingAs($user)
            ->put(route('automations.update', $automation), $this->attributeAutomationPayload([
                'type' => 'attribute_changed',
                'attribute' => 'plan',
                'operator' => 'changed_to',
                'value' => 'pro',
            ], $tag))
            ->assertRedirect(route('automations.index'));

        $this->assertSame([
            'type' => 'attribute_changed',
            'attribute' => 'plan',
            'operator' => 'changed_to',
            'value' => 'pro',
        ], $automation->refresh()->condition);

        $this->actingAs($user)
            ->put(route('automations.update', $automation), $this->attributeAutomationPayload([
                'type' => 'tag_added',
                'value' => $tag->id,
            ], $tag))
            ->assertRedirect(route('automations.index'));

        $condition = $automation->refresh()->condition;
        $this->assertSame('tag_added', $condition['type']);
        $this->assertEquals($tag->id, $condition['value']);
        $this->assertArrayNotHasKey('attribute', $condition);
    }

    public function test_pages_list_client_attribute_names_sorted_by_name(): void
    {
        $user = User::factory()->create();
        $teammate = User::factory()->create(['client_id' => $user->client_id]);
        ContactAttribute::factory()->for($user)->create(['name' => 'plan']);
        ContactAttribute::factory()->for($teammate)->create(['name' => 'company']);
        ContactAttribute::factory()->create(['name' => 'foreign']);
        $automation = Automation::factory()->for($user)->create();

        $expected = ['company', 'plan'];

        $this->actingAs($user)->get(route('automations.index'))
            ->assertInertia(fn (Assert $page): Assert => $page->where('catalogs.attributes', $expected));

        $this->actingAs($user)->get(route('automations.create'))
            ->assertInertia(fn (Assert $page): Assert => $page->where('attributes', $expected));

        $this->actingAs($user)->get(route('automations.edit', $automation))
            ->assertInertia(fn (Assert $page): Assert => $page->where('attributes', $expected));
    }

    /**
     * @param  array<string, mixed>  $condition
     * @return array<string, mixed>
     */
    private function attributeAutomationPayload(array $condition, Tag $tag): array
    {
        return [
            'name' => 'Plan Trigger',
            'type' => 'simple',
            'condition' => $condition,
            'actions' => [
                ['type' => 'add_tag', 'value' => $tag->id],
            ],
        ];
    }
}
