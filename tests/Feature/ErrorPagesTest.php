<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{int, string, string}>
     */
    public static function pages(): array
    {
        return [
            '401' => [401, 'Log in to see this page', 'Log in'],
            '403' => [403, 'You do not have access to this page', 'Go to the dashboard'],
            '404' => [404, 'We cannot find this page', 'Go to the dashboard'],
            '419' => [419, 'This page expired', 'Reload the page'],
            '429' => [429, 'Too many requests', 'Wait one minute'],
            '500' => [500, 'The server had an error', 'php artisan byomailer:doctor'],
            '503' => [503, 'BYOMailer is not available now', 'Try again in a few minutes'],
        ];
    }

    #[DataProvider('pages')]
    public function test_each_error_page_says_what_happened_and_what_to_do(int $status, string $title, string $action): void
    {
        $html = view("errors.{$status}")->render();

        $this->assertStringContainsString($title, $html);
        $this->assertStringContainsString($action, $html);
        $this->assertStringContainsString('BYOMailer', $html);
        $this->assertStringContainsString((string) $status, $html);
    }

    public function test_a_missing_page_shows_the_byomailer_404_page(): void
    {
        config(['app.debug' => false]);

        $this->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertSee('We cannot find this page')
            ->assertSee('href="/"', false);
    }

    public function test_the_error_pages_load_no_build_assets(): void
    {
        // An error page must render when the frontend build is missing.
        $html = view('errors.500')->render();

        $this->assertStringNotContainsString('/build/', $html);
    }
}
