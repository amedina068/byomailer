<?php

namespace Tests\Unit;

use App\Models\Block;
use App\Models\User;
use App\Support\ReplacesBlockVariables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReplacesBlockVariablesTest extends TestCase
{
    use RefreshDatabase;
    use ReplacesBlockVariables;

    public function test_replaces_block_variable_with_html_content(): void
    {
        $user = User::factory()->create();
        Block::factory()->for($user)->create([
            'name' => 'welcome',
            'html_content' => '<h1>Welcome!</h1>',
        ]);

        $result = $this->replaceBlockVariables('Hello {{ block.welcome }}', $user->client);

        $this->assertSame('Hello <h1>Welcome!</h1>', $result);
    }

    public function test_replaces_multiple_block_variables(): void
    {
        $user = User::factory()->create();
        Block::factory()->for($user)->create([
            'name' => 'header',
            'html_content' => '<h1>Header</h1>',
        ]);
        Block::factory()->for($user)->create([
            'name' => 'footer',
            'html_content' => '<p>Footer</p>',
        ]);

        $result = $this->replaceBlockVariables('{{ block.header }} content {{ block.footer }}', $user->client);

        $this->assertSame('<h1>Header</h1> content <p>Footer</p>', $result);
    }

    public function test_removes_unknown_block_variable(): void
    {
        $user = User::factory()->create();

        $result = $this->replaceBlockVariables('Hello {{ block.missing }}', $user->client);

        $this->assertSame('Hello ', $result);
    }

    public function test_ignores_non_block_variables(): void
    {
        $user = User::factory()->create();

        $result = $this->replaceBlockVariables('Hello {{ name }}', $user->client);

        $this->assertSame('Hello {{ name }}', $result);
    }

    public function test_returns_empty_content_unchanged(): void
    {
        $user = User::factory()->create();

        $result = $this->replaceBlockVariables('', $user->client);

        $this->assertSame('', $result);
    }

    /**
     * `ClientScope` is a no-op with no authenticated user, which is exactly the
     * situation in queued sends and the sequence cron. Without an explicit
     * `client_id` filter another tenant's block of the same name would be
     * pasted into the email.
     */
    public function test_never_uses_a_block_belonging_to_another_client(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Block::factory()->for($other)->create([
            'name' => 'footer',
            'html_content' => '<p>Other tenant footer</p>',
        ]);

        $this->assertGuest();

        $result = $this->replaceBlockVariables('{{ block.footer }}', $user->client);

        $this->assertSame('', $result);
    }

    /**
     * A block stores HTML. Substituting it into the plain part as-is ships
     * `<p>` tags to the reader, which is both ugly and a spam signal.
     */
    public function test_text_mode_converts_the_block_html_to_text(): void
    {
        $user = User::factory()->create();
        Block::factory()->for($user)->create([
            'name' => 'footer',
            'html_content' => '<p>Acme Inc, 1 Main St</p><p><a href="https://acme.test/prefs">Manage preferences</a></p>',
        ]);

        $result = $this->replaceBlockVariablesAsText('Bye.

{{ block.footer }}', $user->client);

        $this->assertStringNotContainsString('<p>', $result);
        $this->assertStringContainsString('Acme Inc, 1 Main St', $result);
        $this->assertStringContainsString('https://acme.test/prefs', $result);
    }

    /**
     * Block HTML is data, not a replacement pattern. `$1` in a block must not
     * be read as a backreference.
     */
    public function test_a_dollar_sign_in_block_content_is_not_a_backreference(): void
    {
        $user = User::factory()->create();
        Block::factory()->for($user)->create([
            'name' => 'price',
            'html_content' => '<p>Only $1 today, save \\1 more</p>',
        ]);

        $result = $this->replaceBlockVariables('{{ block.price }}', $user->client);

        $this->assertSame('<p>Only $1 today, save \\1 more</p>', $result);
    }

    public function test_block_content_preserves_contact_variables(): void
    {
        $user = User::factory()->create();
        Block::factory()->for($user)->create([
            'name' => 'greeting',
            'html_content' => '<p>Hello {{ name }}!</p>',
        ]);

        $result = $this->replaceBlockVariables('{{ block.greeting }}', $user->client);

        $this->assertSame('<p>Hello {{ name }}!</p>', $result);
    }
}
