<?php

namespace Tests\Feature;

use App\Models\ContactAttribute;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SequenceVariablesPrefillTest extends TestCase
{
    use RefreshDatabase;

    private function makeSequence(User $user, string $html): Sequence
    {
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();
        $step->message()->update(['html_content' => $html]);

        return $sequence;
    }

    public function test_never_saved_sequence_prefills_global_default(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        ContactAttribute::factory()->withDefault('Acme Corp')->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'company',
        ]);
        $sequence = $this->makeSequence($user, '<p>{{ company }}</p>');

        $this->actingAs($user)
            ->get(route('sequences.show', $sequence))
            ->assertInertia(fn (Assert $page) => $page
                ->where('variableDefaults.company', 'Acme Corp'),
            );
    }

    public function test_sequence_saved_with_empty_string_prefills_global_default(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        ContactAttribute::factory()->withDefault('Acme Corp')->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'company',
        ]);
        $sequence = $this->makeSequence($user, '<p>{{ company }}</p>');
        $sequence->forceFill(['content_variables' => ['company' => '']])->save();

        $this->actingAs($user)
            ->get(route('sequences.show', $sequence))
            ->assertInertia(fn (Assert $page) => $page
                ->where('variableDefaults.company', 'Acme Corp'),
            );
    }

    public function test_explicit_sequence_value_is_not_overridden_by_global_default(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        ContactAttribute::factory()->withDefault('Acme Corp')->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'company',
        ]);
        $sequence = $this->makeSequence($user, '<p>{{ company }}</p>');
        $sequence->forceFill(['content_variables' => ['company' => 'My Own Co']])->save();

        $this->actingAs($user)
            ->get(route('sequences.show', $sequence))
            ->assertInertia(fn (Assert $page) => $page
                ->where('variableDefaults.company', 'My Own Co'),
            );
    }

    public function test_variable_added_after_prior_save_prefills_global_default(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        ContactAttribute::factory()->withDefault('FULL10')->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'coupon',
        ]);
        $sequence = $this->makeSequence($user, '<p>{{ company }}</p>');
        $sequence->forceFill(['content_variables' => ['company' => 'My Own Co']])->save();
        $sequence->steps()->first()->message()->update([
            'html_content' => '<p>{{ company }} {{ coupon }}</p>',
        ]);

        $this->actingAs($user)
            ->get(route('sequences.show', $sequence))
            ->assertInertia(fn (Assert $page) => $page
                ->where('variableDefaults.company', 'My Own Co')
                ->where('variableDefaults.coupon', 'FULL10'),
            );
    }

    public function test_stored_zero_string_is_not_treated_as_blank(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        ContactAttribute::factory()->withDefault('FULL10')->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'coupon',
        ]);
        $sequence = $this->makeSequence($user, '<p>{{ coupon }}</p>');
        $sequence->forceFill(['content_variables' => ['coupon' => '0']])->save();

        $this->actingAs($user)
            ->get(route('sequences.show', $sequence))
            ->assertInertia(fn (Assert $page) => $page
                ->where('variableDefaults.coupon', '0'),
            );
    }

    public function test_global_variable_defaults_prop_is_shared_with_page(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        ContactAttribute::factory()->withDefault('Acme Corp')->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'company',
        ]);
        $sequence = $this->makeSequence($user, '<p>{{ company }}</p>');

        $this->actingAs($user)
            ->get(route('sequences.show', $sequence))
            ->assertInertia(fn (Assert $page) => $page
                ->where('globalVariableDefaults.company', 'Acme Corp'),
            );
    }
}
