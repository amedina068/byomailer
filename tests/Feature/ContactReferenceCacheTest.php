<?php

namespace Tests\Feature;

use App\Models\MailList;
use App\Models\Sequence;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContactReferenceCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_data_is_cached_across_requests(): void
    {
        $user = User::factory()->create();
        MailList::factory()->for($user)->create();
        Tag::factory()->for($user)->create();
        Sequence::factory()->for($user)->create(['status' => 'live']);

        $this->actingAs($user)->get(route('contacts.index'))->assertOk();

        DB::enableQueryLog();
        $this->actingAs($user)->get(route('contacts.index'))->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertTrue(
            $queries->every(fn (string $q): bool => ! str_contains($q, 'from "lists"')),
            'Reference lists should be served from cache on the second request.'
        );
        $this->assertTrue(
            $queries->every(fn (string $q): bool => ! str_contains($q, 'from "tags"')),
            'Reference tags should be served from cache on the second request.'
        );
        $this->assertTrue(
            $queries->every(fn (string $q): bool => ! str_contains($q, 'from "sequences"')),
            'Live sequences should be served from cache on the second request.'
        );
    }

    public function test_reference_data_still_renders_correct_values(): void
    {
        $user = User::factory()->create();
        MailList::factory()->for($user)->create(['name' => 'Newsletter Subscribers']);
        Tag::factory()->for($user)->create(['name' => 'VIP']);

        $this->actingAs($user)
            ->get(route('contacts.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('lists.0.name', 'Newsletter Subscribers')
                ->where('tags.0.name', 'VIP')
            );
    }
}
