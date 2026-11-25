<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;
use Tests\TestCase;

class SidebarNavigationTest extends TestCase
{
    public function test_sidebar_navigation_links_are_shared_with_inertia(): void
    {
        $request = Request::create('/');
        $request->setLaravelSession(app('session')->driver());

        /** @var HandleInertiaRequests $middleware */
        $middleware = app(HandleInertiaRequests::class);

        $shared = $middleware->share($request);
        $this->assertArrayHasKey('navigation', $shared);

        $links = $shared['navigation']['main'] ?? [];

        $this->assertSame(
            ['Dashboard', 'Reputation', 'Lists', 'Contacts', 'Tags', 'Segments', 'Attributes', 'Newsletters', 'Sequences', 'Automations', 'Visual Automations', 'Projects', 'Blocks', 'Templates', 'Email Logs'],
            array_column($links, 'title')
        );

        $this->assertSame(
            ['dashboard', 'reputation', 'lists', 'contacts', 'tags', 'segments', 'attributes', 'newsletters', 'sequences', 'automations', 'visual-automations', 'projects', 'blocks', 'email-templates', 'email-logs'],
            array_column($links, 'key')
        );

        $this->assertSame(
            [
                route('dashboard', absolute: false),
                route('reputation.show', absolute: false),
                route('lists.index', absolute: false),
                route('contacts.index', absolute: false),
                route('tags.index', absolute: false),
                route('segments.index', absolute: false),
                route('attributes.index', absolute: false),
                route('newsletters.index', absolute: false),
                route('sequences.index', absolute: false),
                route('automations.index', absolute: false),
                route('visual-automations.index', absolute: false),
                route('projects.index', absolute: false),
                route('blocks.index', absolute: false),
                route('email-templates.index', absolute: false),
                route('email-logs.index', absolute: false),
            ],
            array_column($links, 'href')
        );
    }
}
