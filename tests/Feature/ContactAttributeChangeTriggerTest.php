<?php

namespace Tests\Feature;

use App\Events\ContactAttributesChanged;
use App\Jobs\ProcessContactImportBatchJob;
use App\Listeners\RunAutomationsForChangedAttributes;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\MailList;
use App\Models\Tag;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ContactAttributeChangeTriggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_listener_is_registered_once(): void
    {
        $this->assertCount(1, Event::getRawListeners()[ContactAttributesChanged::class] ?? []);
    }

    public function test_event_dispatch_waits_for_the_transaction_to_commit(): void
    {
        $contact = Contact::factory()->for(User::factory())->create();
        $received = [];

        Event::listen(ContactAttributesChanged::class, function (ContactAttributesChanged $event) use (&$received): void {
            $received[] = $event->changes;
        });

        DB::transaction(function () use ($contact, &$received): void {
            ContactAttributesChanged::dispatch($contact, ['plan' => ['old' => 'free', 'new' => 'pro']]);

            $this->assertSame([], $received);
        });

        $this->assertSame([['plan' => ['old' => 'free', 'new' => 'pro']]], $received);
    }

    public function test_event_dispatch_is_dropped_when_the_transaction_rolls_back(): void
    {
        $contact = Contact::factory()->for(User::factory())->create();
        $received = [];

        Event::listen(ContactAttributesChanged::class, function (ContactAttributesChanged $event) use (&$received): void {
            $received[] = $event->changes;
        });

        try {
            DB::transaction(function () use ($contact): void {
                ContactAttributesChanged::dispatch($contact, ['plan' => ['old' => 'free', 'new' => 'pro']]);

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $received);
    }

    public function test_changed_value_dispatches_old_and_new(): void
    {
        $contact = $this->contactWithLiveTrigger(['plan' => 'free']);

        $contact->update(['extra_attributes' => ['plan' => 'pro']]);

        $this->assertChangesDispatched(['plan' => ['old' => 'free', 'new' => 'pro']]);
    }

    public function test_added_key_dispatches_null_old_value(): void
    {
        $contact = $this->contactWithLiveTrigger(['plan' => 'free']);

        $contact->update(['extra_attributes' => ['plan' => 'free', 'seats' => '3']]);

        $this->assertChangesDispatched(['seats' => ['old' => null, 'new' => '3']]);
    }

    public function test_removed_key_dispatches_null_new_value(): void
    {
        $contact = $this->contactWithLiveTrigger(['plan' => 'free', 'seats' => '3']);

        $contact->update(['extra_attributes' => ['seats' => '3']]);

        $this->assertChangesDispatched(['plan' => ['old' => 'free', 'new' => null]]);
    }

    public function test_unchanged_or_whitespace_only_values_do_not_dispatch(): void
    {
        $contact = $this->contactWithLiveTrigger(['plan' => 'pro', 'note' => '']);

        $contact->update(['extra_attributes' => ['plan' => 'pro', 'note' => '']]);
        $contact->update(['extra_attributes' => ['plan' => ' pro ', 'note' => '   ']]);

        Event::assertNotDispatched(ContactAttributesChanged::class);
    }

    public function test_new_contact_with_attributes_dispatches_null_old_values(): void
    {
        $user = User::factory()->create();
        $this->liveTrigger($user);
        Event::fake([ContactAttributesChanged::class]);

        Contact::factory()->for($user)->withAttributes(['plan' => 'pro'])->create();

        $this->assertChangesDispatched(['plan' => ['old' => null, 'new' => 'pro']]);
    }

    public function test_new_contact_without_attributes_does_not_dispatch(): void
    {
        $user = User::factory()->create();
        $this->liveTrigger($user);
        Event::fake([ContactAttributesChanged::class]);

        Contact::factory()->for($user)->create();

        Event::assertNotDispatched(ContactAttributesChanged::class);
    }

    public function test_non_string_values_are_normalized(): void
    {
        $contact = $this->contactWithLiveTrigger(['items' => ['a', 'b']]);

        $contact->update(['extra_attributes' => ['items' => ['a', 'b'], 'seats' => 5, 'vip' => true, 'off' => false]]);

        $this->assertChangesDispatched([
            'seats' => ['old' => null, 'new' => '5'],
            'vip' => ['old' => null, 'new' => '1'],
        ]);
    }

    public function test_array_value_change_is_compared_as_json(): void
    {
        $contact = $this->contactWithLiveTrigger(['items' => ['a']]);

        $contact->update(['extra_attributes' => ['items' => ['a', 'b']]]);

        $this->assertChangesDispatched(['items' => ['old' => '["a"]', 'new' => '["a","b"]']]);
    }

    public function test_saving_only_the_name_does_not_dispatch(): void
    {
        $contact = $this->contactWithLiveTrigger(['plan' => 'free']);

        $automationQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$automationQueries): void {
            if (str_contains($query->sql, 'automations')) {
                $automationQueries++;
            }
        });

        $contact->update(['name' => 'Someone Else']);

        Event::assertNotDispatched(ContactAttributesChanged::class);
        $this->assertSame(0, $automationQueries);
    }

    public function test_no_dispatch_without_a_live_attribute_trigger_for_the_user(): void
    {
        $user = User::factory()->create();
        Automation::factory()->for($user)->paused()->create(['condition' => $this->condition()]);
        Automation::factory()->for($user)->create();
        Automation::factory()->for($user)->create(['type' => Automation::TYPE_MULTISTEP, 'condition' => $this->condition()]);
        Automation::factory()->create(['condition' => $this->condition()]);
        $contact = Contact::factory()->for($user)->withAttributes(['plan' => 'free'])->create();
        Event::fake([ContactAttributesChanged::class]);

        $contact->update(['extra_attributes' => ['plan' => 'pro']]);

        Event::assertNotDispatched(ContactAttributesChanged::class);
    }

    /**
     * @return array<string, array{string, string, ?string, string, ?string, ?string, bool}>
     */
    public static function listenerCases(): array
    {
        return [
            'changed fires' => ['plan', 'changed', null, 'plan', 'free', 'pro', true],
            'changed_to from another value fires' => ['plan', 'changed_to', 'pro', 'plan', 'free', 'pro', true],
            'changed_to from null fires' => ['plan', 'changed_to', 'pro', 'plan', null, 'pro', true],
            'changed_to is case-sensitive' => ['plan', 'changed_to', 'pro', 'plan', 'free', 'Pro', false],
            'changed_to away from the value does not fire' => ['plan', 'changed_to', 'pro', 'plan', 'pro', 'free', false],
            'other attribute does not fire' => ['plan', 'changed', null, 'seats', '1', '2', false],
            'name with a dot fires' => ['plan.tier', 'changed', null, 'plan.tier', null, 'gold', true],
            'name with a quote fires' => ["o'plan", 'changed_to', 'pro', "o'plan", null, 'pro', true],
            'name with a space fires' => ['plan name', 'changed_to', 'pro', 'plan name', 'free', 'pro', true],
        ];
    }

    #[DataProvider('listenerCases')]
    public function test_listener_matches_the_condition(string $attribute, string $operator, ?string $value, string $changedKey, ?string $old, ?string $new, bool $fires): void
    {
        $contact = Contact::factory()->for(User::factory())->create();
        $this->actingAsTenant($contact->client_id);
        $tag = $this->tagTrigger($contact->user, $this->condition($attribute, $operator, $value));

        app(RunAutomationsForChangedAttributes::class)->handle(
            new ContactAttributesChanged($contact, [$changedKey => ['old' => $old, 'new' => $new]]),
        );

        $this->assertSame($fires, $contact->tags()->whereKey($tag->id)->exists());
    }

    public function test_listener_ignores_paused_and_other_user_automations(): void
    {
        $contact = Contact::factory()->for(User::factory())->create();
        $this->actingAsTenant($contact->client_id);
        $pausedTag = $this->tagTrigger($contact->user, $this->condition(), live: false);
        $otherTag = $this->tagTrigger(User::factory()->create(), $this->condition());

        app(RunAutomationsForChangedAttributes::class)->handle(
            new ContactAttributesChanged($contact, ['plan' => ['old' => 'free', 'new' => 'pro']]),
        );

        $this->assertFalse($contact->tags()->whereKey([$pausedTag->id, $otherTag->id])->exists());
    }

    public function test_import_row_that_changes_plan_to_pro_runs_the_automation(): void
    {
        $user = User::factory()->create();
        $tag = $this->tagTrigger($user, $this->condition('plan', 'changed_to', 'pro'));
        $contact = Contact::factory()->for($user)->withAttributes(['plan' => 'free'])->create(['email' => 'lead@example.com']);

        $this->importRow($user, 'lead@example.com', ['plan' => 'pro']);

        $this->assertTrue(Tenant::run($user->client_id, fn (): bool => $contact->tags()->whereKey($tag->id)->exists()));
    }

    public function test_import_row_that_keeps_plan_pro_does_not_run_the_automation(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->withAttributes(['plan' => 'pro'])->create(['email' => 'lead@example.com']);
        $tag = $this->tagTrigger($user, $this->condition('plan', 'changed_to', 'pro'));

        $this->importRow($user, 'lead@example.com', ['plan' => 'pro']);

        $this->assertFalse(Tenant::run($user->client_id, fn (): bool => $contact->tags()->whereKey($tag->id)->exists()));
    }

    public function test_api_update_that_changes_plan_to_pro_runs_the_automation(): void
    {
        $user = User::factory()->create();
        $tag = $this->tagTrigger($user, $this->condition('plan', 'changed_to', 'pro'));
        $contact = Contact::factory()->for($user)->withAttributes(['plan' => 'free'])->create();
        Sanctum::actingAs($user, ['*']);

        $this->putJson("/api/v1/contacts/{$contact->id}", ['plan' => 'pro'])->assertOk();

        $this->assertTrue($contact->tags()->whereKey($tag->id)->exists());
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function importRow(User $user, string $email, array $attributes): void
    {
        $list = MailList::factory()->for($user)->create();
        $contactImport = ContactImport::factory()->processing()->for($user)->for($list, 'mailList')->create(['total_count' => 1]);
        $rows = [['email' => $email, 'name' => null, 'tags' => null, 'attributes' => $attributes]];

        [$job] = (new ProcessContactImportBatchJob($user, $list, $contactImport, $rows))->withFakeBatch();

        app()->call([$job, 'handle']);
    }

    /**
     * @param  array{type: string, attribute: string, operator: string, value: ?string}  $condition
     */
    private function tagTrigger(User $user, array $condition, bool $live = true): Tag
    {
        $tag = Tag::factory()->for($user)->create();
        Automation::factory()->for($user)->create([
            'condition' => $condition,
            'actions' => [['type' => 'add_tag', 'value' => $tag->id]],
            'is_live' => $live,
        ]);

        return $tag;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function contactWithLiveTrigger(array $attributes): Contact
    {
        $user = User::factory()->create();
        $this->liveTrigger($user);
        $contact = Contact::factory()->for($user)->create(['extra_attributes' => $attributes]);
        Event::fake([ContactAttributesChanged::class]);

        return $contact;
    }

    private function liveTrigger(User $user): Automation
    {
        return Automation::factory()->for($user)->create(['condition' => $this->condition(), 'actions' => []]);
    }

    /**
     * @return array{type: string, attribute: string, operator: string, value: ?string}
     */
    private function condition(string $attribute = 'plan', string $operator = 'changed', ?string $value = null): array
    {
        return ['type' => 'attribute_changed', 'attribute' => $attribute, 'operator' => $operator, 'value' => $value];
    }

    /**
     * @param  array<string, array{old: ?string, new: ?string}>  $expected
     */
    private function assertChangesDispatched(array $expected): void
    {
        Event::assertDispatchedTimes(ContactAttributesChanged::class, 1);
        Event::assertDispatched(ContactAttributesChanged::class, fn (ContactAttributesChanged $event): bool => $event->changes === $expected);
    }
}
