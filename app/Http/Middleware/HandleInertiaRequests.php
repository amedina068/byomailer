<?php

namespace App\Http\Middleware;

use App\Support\MarketingOgImage;
use App\Support\OnboardingChecklist;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * The user fields that the front end reads. The `auth.user` prop is in the
     * HTML of every page, so it never carries a loaded relation or a secret.
     *
     * @var list<string>
     */
    private const array SHARED_USER_FIELDS = [
        'id',
        'uuid',
        'name',
        'email',
        'role',
        'email_verified_at',
        'invite_expires_at',
        'archived_at',
        'created_at',
        'updated_at',
    ];

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'csrf_token' => csrf_token(),
            'name' => config('app.name'),
            'appUrl' => rtrim((string) config('app.url'), '/'),
            'ogImage' => MarketingOgImage::forRoute($request->route()?->getName()),
            'auth' => [
                'user' => $request->user()?->only(self::SHARED_USER_FIELDS),
                'timezone' => fn () => $request->user()?->client?->timezone ?? 'UTC',
            ],
            'sesAccountStatus' => fn () => $this->sesAccountStatusProps($request),
            'hasPostalAddress' => fn (): bool => trim((string) $request->user()?->client?->postal_address) !== '',
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'navigation' => [
                'main' => $this->mainNavigationLinks($request),
            ],
            'onboardingChecklist' => fn () => $request->user()?->client ? OnboardingChecklist::forClient($request->user()->client) : null,
            'flash' => fn () => [
                'newToken' => $request->session()->get('newToken'),
                'createdTag' => $request->session()->get('createdTag'),
                'createdList' => $request->session()->get('createdList'),
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * SES account state from the last quota fetch of the signed-in user's own
     * credential. Null for guests, without a credential, or before the first fetch.
     *
     * @return array{enforcement_status: string|null, sending_enabled: bool}|null
     */
    private function sesAccountStatusProps(Request $request): ?array
    {
        $credential = $request->user()?->sesCredential;

        if ($credential === null || $credential->quota_fetched_at === null) {
            return null;
        }

        return [
            'enforcement_status' => $credential->enforcement_status,
            'sending_enabled' => $credential->sending_enabled,
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function mainNavigationLinks(Request $request): array
    {
        $links = [
            [
                'key' => 'dashboard',
                'title' => 'Dashboard',
                'href' => route('dashboard', absolute: false),
                'group' => 'Overview',
            ],
            [
                'key' => 'reputation',
                'title' => 'Reputation',
                'href' => route('reputation.show', absolute: false),
                'group' => 'Overview',
            ],
            [
                'key' => 'lists',
                'title' => 'Lists',
                'href' => route('lists.index', absolute: false),
                'group' => 'Audience',
            ],
            [
                'key' => 'contacts',
                'title' => 'Contacts',
                'href' => route('contacts.index', absolute: false),
                'group' => 'Audience',
            ],
            [
                'key' => 'tags',
                'title' => 'Tags',
                'href' => route('tags.index', absolute: false),
                'group' => 'Audience',
            ],
            [
                'key' => 'segments',
                'title' => 'Segments',
                'href' => route('segments.index', absolute: false),
                'group' => 'Audience',
            ],
            [
                'key' => 'attributes',
                'title' => 'Attributes',
                'href' => route('attributes.index', absolute: false),
                'group' => 'Audience',
            ],
            [
                'key' => 'newsletters',
                'title' => 'Newsletters',
                'href' => route('newsletters.index', absolute: false),
                'group' => 'Sending',
            ],
            [
                'key' => 'sequences',
                'title' => 'Sequences',
                'href' => route('sequences.index', absolute: false),
                'group' => 'Sending',
            ],
            [
                'key' => 'automations',
                'title' => 'Automations',
                'href' => route('automations.index', absolute: false),
                'group' => 'Sending',
            ],
            [
                'key' => 'visual-automations',
                'title' => 'Visual Automations',
                'href' => route('visual-automations.index', absolute: false),
                'group' => 'Sending',
            ],
            [
                'key' => 'projects',
                'title' => 'Projects',
                'href' => route('projects.index', absolute: false),
                'group' => 'Sending',
            ],
            [
                'key' => 'blocks',
                'title' => 'Blocks',
                'href' => route('blocks.index', absolute: false),
                'group' => 'Content',
            ],
            [
                'key' => 'email-templates',
                'title' => 'Templates',
                'href' => route('email-templates.index', absolute: false),
                'group' => 'Content',
            ],
            [
                'key' => 'email-logs',
                'title' => 'Email Logs',
                'href' => route('email-logs.index', absolute: false),
                'group' => 'Activity',
            ],
        ];

        // Only the owner sees the admin area. Everyone else gets a 404 there.
        if ($request->user()?->isOwner()) {
            $links[] = [
                'key' => 'admin',
                'title' => 'Admin',
                'href' => route('admin.users.index', absolute: false),
                'group' => 'Instance',
            ];
        }

        return $links;
    }
}
