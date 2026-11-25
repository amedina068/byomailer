<?php

namespace Tests\Feature;

use App\Actions\CreateTag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateTagTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_puts_the_tag_in_the_client_of_the_user_and_records_the_author(): void
    {
        $user = User::factory()->create();

        $tag = (new CreateTag)->handle($user, 'VIP');

        $this->assertTrue($tag->exists);
        $this->assertSame('VIP', $tag->name);
        $this->assertSame($user->client_id, $tag->client_id);
        $this->assertSame($user->id, $tag->user_id);
    }
}
