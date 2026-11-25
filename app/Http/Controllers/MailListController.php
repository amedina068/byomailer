<?php

namespace App\Http\Controllers;

use App\Actions\CreateMailList;
use App\Actions\UpdateMailList;
use App\Enums\ContactImportStatus;
use App\Http\Requests\StoreMailListRequest;
use App\Http\Requests\UpdateMailListRequest;
use App\Http\Resources\ContactImportResource;
use App\Http\Resources\EmailIdentityResource;
use App\Http\Resources\MailListResource;
use App\Http\Resources\TopicResource;
use App\Models\EmailIdentity;
use App\Models\MailList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MailListController extends Controller
{
    public function index(Request $request): Response
    {
        $lists = MailList::query()
            ->withCount('contacts')
            ->latest('id')
            ->get();

        return Inertia::render('lists/Index', [
            'lists' => MailListResource::collection($lists)->resolve($request),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('lists/Create');
    }

    public function store(StoreMailListRequest $request, CreateMailList $createMailList): RedirectResponse
    {
        $list = $createMailList->handle($request->user(), $request->validated('name'));

        if ($request->boolean('_return_back')) {
            return back()->with('createdList', [
                'id' => $list->id,
                'name' => $list->name,
            ]);
        }

        $redirectTo = $request->input('_redirect');

        if ($redirectTo === 'contacts.import') {
            return to_route('contacts.import', ['list_id' => $list->getKey()]);
        }

        return to_route('lists.index');
    }

    public function show(Request $request, int $listId): Response
    {
        $list = MailList::query()
            ->withCount(['contacts', 'topics'])
            ->findOrFail($listId);

        $imports = $list->contactImports()
            ->latest('id')
            ->get();

        $topics = $list->topics()
            ->withCount(['contacts as subscribed_count' => fn ($q) => $q->whereNull('contact_topic.unsubscribed_at')])
            ->latest('id')
            ->get();

        return Inertia::render('lists/Show', [
            'list' => MailListResource::make($list)->resolve($request),
            'imports' => ContactImportResource::collection($imports)->resolve($request),
            'importStatuses' => collect(ContactImportStatus::cases())
                ->map(fn (ContactImportStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                    'in_progress' => $status->inProgress(),
                ])
                ->values(),
            'topics' => TopicResource::collection($topics)->resolve($request),
        ]);
    }

    public function edit(Request $request, int $listId): Response
    {
        $list = MailList::query()
            ->with('message')
            ->findOrFail($listId);

        $emailIdentities = EmailIdentity::query()->get();

        return Inertia::render('lists/Edit', [
            'list' => MailListResource::make($list)->resolve($request),
            'emailIdentities' => EmailIdentityResource::collection($emailIdentities)->resolve($request),
        ]);
    }

    public function update(UpdateMailListRequest $request, int $listId, UpdateMailList $updateMailList): RedirectResponse
    {
        $list = MailList::query()
            ->findOrFail($listId);

        $updateMailList->handle($request->user(), $list, [
            ...$request->validated(),
            'opt_in_enabled' => $request->boolean('opt_in_enabled'),
        ]);

        return to_route('lists.show', $list);
    }
}
