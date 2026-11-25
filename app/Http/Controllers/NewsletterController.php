<?php

namespace App\Http\Controllers;

use App\Actions\DeleteNewsletter;
use App\Actions\UpdateNewsletterContent;
use App\Actions\UpdateNewsletterLabels;
use App\Enums\NewsletterStatus;
use App\Http\Requests\NewsletterContactsPreviewRequest;
use App\Http\Requests\NewsletterContentRequest;
use App\Http\Requests\NewsletterFiltersRequest;
use App\Http\Requests\NewsletterScheduleRequest;
use App\Http\Requests\NewsletterTitleRequest;
use App\Http\Resources\ContactResource;
use App\Http\Resources\EmailIdentityResource;
use App\Http\Resources\EmailTemplateResource;
use App\Http\Resources\MailListResource;
use App\Http\Resources\NewsletterResource;
use App\Http\Resources\SegmentResource;
use App\Http\Resources\TagResource;
use App\Http\Resources\TopicResource;
use App\Models\ContactMail;
use App\Models\EmailTemplate;
use App\Models\Newsletter;
use App\Models\Project;
use App\Models\Topic;
use App\Services\ContactFilterService;
use App\Services\FilterPreparationService;
use App\Services\LinkClickReport;
use App\Services\NewsletterScheduleService;
use App\Services\SesPermissionChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class NewsletterController extends Controller
{
    private const WIZARD_STEPS = ['content', 'variables', 'filters', 'preview', 'schedule'];

    public function __construct(
        private FilterPreparationService $filterPreparationService,
        private ContactFilterService $contactFilterService,
        private NewsletterScheduleService $newsletterScheduleService,
    ) {}

    public function index(Request $request): Response
    {
        $newsletters = Newsletter::query()
            ->with(['message.template', 'messageB.template', 'emailIdentity', 'segment', 'projects:projects.id'])
            ->withMetricsCounts()
            ->latest('id')
            ->get();

        $checker = app(SesPermissionChecker::class);

        return Inertia::render('newsletters/Index', [
            'newsletters' => NewsletterResource::collection($newsletters)->resolve($request),
            'newsletterStatuses' => NewsletterStatus::options(),
            'projects' => Project::query()->orderBy('name')->get(['id', 'name']),
            'missingPermissions' => $checker->getMissing($request->user(), SesPermissionChecker::NEWSLETTER_BULK),
        ]);
    }

    public function show(Request $request, $newsletterId, LinkClickReport $linkClickReport): Response
    {
        $statusFilter = (string) $request->query('status', 'all');
        $linkFilter = LinkClickReport::isValidKey($request->query('link')) ? (string) $request->query('link') : null;

        $newsletter = Newsletter::query()
            ->with(['message.template', 'messageB.template', 'emailIdentity', 'segment'])
            ->withMetricsCounts((int) $newsletterId)
            ->findOrFail($newsletterId);

        $recipientQuery = $newsletter->contactMails()
            ->with('contact.tags');

        if ($statusFilter !== 'all') {
            $recipientQuery->where('status', $statusFilter);
        }

        if ($linkFilter !== null) {
            $linkClickReport->filterContactMails($recipientQuery->getQuery(), $newsletter, $linkFilter);
        }

        $recipients = $recipientQuery
            ->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through(function (ContactMail $contactMail) use ($request): array {
                return [
                    ...ContactResource::make($contactMail->contact)->resolve($request),
                    'contact_mail_status' => $contactMail->status,
                    'link_clicks_count' => $contactMail->getAttribute('link_clicks_count'),
                    'link_first_clicked_at' => $contactMail->getAttribute('link_first_clicked_at'),
                ];
            });

        $statusOptions = $newsletter->contactMails()
            ->whereNotNull('status')
            ->distinct()
            ->orderBy('status')
            ->pluck('status')
            ->values()
            ->all();

        if ($statusFilter !== 'all' && ! in_array($statusFilter, $statusOptions, true)) {
            $statusFilter = 'all';
        }

        return Inertia::render('newsletters/Show', [
            'newsletter' => NewsletterResource::make($newsletter)->resolve($request),
            'newsletterStatuses' => NewsletterStatus::options(),
            'recipients' => $recipients,
            'recipientStatusFilter' => $statusFilter,
            'recipientStatusOptions' => $statusOptions,
            'recipientLinkFilter' => $linkFilter,
            'linkClicks' => Inertia::defer(fn (): array => $linkClickReport->summary($newsletter)),
        ]);
    }

    public function updateTitle(NewsletterTitleRequest $request, UpdateNewsletterLabels $updateNewsletterLabels, $newsletterId): RedirectResponse
    {
        $updateNewsletterLabels->handle(Newsletter::query()->findOrFail($newsletterId), [
            'title' => $request->validated('title'),
        ]);

        return back();
    }

    public function create(Request $request): RedirectResponse
    {
        $newsletter = $request->user()->newsletters()->create([
            'title' => Newsletter::DEFAULT_TITLE,
            'scheduled_at' => null,
            'status' => NewsletterStatus::Draft,
            'filters' => [],
        ]);

        $newsletter->message()->create([
            'user_id' => $request->user()->id,
            'subject' => '',
            'markdown_content' => '',
            'html_content' => '',
            'text_content' => '',
        ]);

        return to_route('newsletters.content.show', $newsletter);
    }

    public function showContent(Request $request, $newsletterId): Response
    {
        $newsletter = $this->getMutableNewsletter($newsletterId);

        return $this->renderWizard($request, $newsletter, 'content');
    }

    public function showFilters(Request $request, $newsletterId): Response
    {
        $newsletter = $this->getMutableNewsletter($newsletterId);

        return $this->renderWizard($request, $newsletter, 'filters');
    }

    public function showPreview(Request $request, $newsletterId): Response
    {
        $newsletter = $this->getMutableNewsletter($newsletterId);

        return $this->renderWizard($request, $newsletter, 'preview');
    }

    public function showSchedule(Request $request, $newsletterId): Response
    {
        $newsletter = $this->getMutableNewsletter($newsletterId);

        return $this->renderWizard($request, $newsletter, 'schedule');
    }

    public function updateContent(NewsletterContentRequest $request, UpdateNewsletterContent $updateNewsletterContent, $newsletterId): RedirectResponse
    {
        $updateNewsletterContent->handle(
            $request->user(),
            $this->getMutableNewsletter($newsletterId),
            $request->contentChanges(),
        );

        return to_route('newsletters.variables.show', $newsletterId);
    }

    public function updateFilters(NewsletterFiltersRequest $request, UpdateNewsletterContent $updateNewsletterContent, $newsletterId): RedirectResponse
    {
        $updateNewsletterContent->handle($request->user(), $this->getMutableNewsletter($newsletterId), [
            'segment_id' => $request->validated('segment_id'),
            'filters' => $request->validated('filters', []),
        ]);

        return to_route('newsletters.preview.show', $newsletterId);
    }

    public function previewContacts(NewsletterContactsPreviewRequest $request): JsonResponse
    {
        $filters = $this->filterPreparationService->prepareFilters(
            $request->validated('filters', []),
            $request->user(),
        );

        $count = $this->contactFilterService->recipientCount(
            $request->user()->client_id,
            $filters,
        );

        return response()->json(['count' => $count]);
    }

    public function schedule(NewsletterScheduleRequest $request, UpdateNewsletterContent $updateNewsletterContent, $newsletterId): RedirectResponse
    {
        $newsletter = $this->getMutableNewsletter($newsletterId);
        $data = $request->validated();
        $senderAndProjects = Arr::only($data, ['email_identity_id', 'project_ids']);

        if ($data['action'] === 'draft') {
            if (! $this->newsletterScheduleService->unschedule($newsletter)) {
                return back()->with('error', NewsletterScheduleService::UNSCHEDULE_FAILED);
            }

            $updateNewsletterContent->handle($request->user(), $newsletter, $senderAndProjects);

            return to_route('newsletters.index')->with('success', 'Draft saved.');
        }

        $timezone = $request->user()->client->timezone ?? 'UTC';
        $sendAt = $data['action'] === 'schedule'
            ? Carbon::createFromFormat('Y-m-d\TH:i', $data['scheduled_for'], $timezone)
            : now();

        DB::transaction(function () use ($request, $updateNewsletterContent, $newsletter, $senderAndProjects, $sendAt): void {
            $updateNewsletterContent->handle($request->user(), $newsletter, $senderAndProjects);
            $this->newsletterScheduleService->scheduleOrSend($request->user(), $newsletter, $sendAt->copy()->utc());
        });

        if ($data['action'] === 'send_now') {
            return to_route('newsletters.show', $newsletter);
        }

        return to_route('newsletters.index')
            ->with('success', 'Newsletter scheduled for '.$sendAt->format('M j, Y, g:i A')." ({$timezone}).");
    }

    public function destroy(DeleteNewsletter $deleteNewsletter, $newsletterId): RedirectResponse
    {
        $newsletter = $this->getMutableNewsletter($newsletterId);

        try {
            $deleteNewsletter->handle($newsletter);
        } catch (ValidationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('newsletters.index');
    }

    private function renderWizard(Request $request, Newsletter $newsletter, string $step = 'content'): Response
    {
        return Inertia::render('newsletters/Create', $this->formProps($request, $newsletter, $step));
    }

    private function formProps(Request $request, ?Newsletter $newsletter = null, string $step = 'content'): array
    {
        $client = $request->user()->client;

        $lists = $client
            ->mailingLists()
            ->orderBy('name')
            ->get();
        $emailIdentities = $client
            ->emailIdentities()
            ->orderBy('email')
            ->get();
        $tags = $client
            ->tags()
            ->orderBy('name')
            ->get();
        $topics = Topic::query()
            ->with(['list:id,name'])
            ->orderBy('name')
            ->get();
        $contactAttributes = $client
            ->contactAttributes()
            ->orderBy('name')
            ->pluck('name')
            ->filter()
            ->unique()
            ->values();

        $defaultTemplate = EmailTemplate::query()
            ->where('is_default', true)
            ->first();

        $filterOptions = $this->filterPreparationService->filterOptionData($client->id);

        return [
            'lists' => MailListResource::collection($lists)->resolve($request),
            'segments' => SegmentResource::collection(
                $client->segments()
                    ->activeOrSelected($newsletter?->segment_id)
                    ->orderBy('name')
                    ->get()
            )->resolve($request),
            'emailIdentities' => EmailIdentityResource::collection($emailIdentities)->resolve($request),
            'tags' => TagResource::collection($tags)->resolve($request),
            'topics' => TopicResource::collection($topics)->resolve($request),
            'contactAttributes' => $contactAttributes->all(),
            'filterNewsletters' => $filterOptions['newsletters'],
            'filterCountries' => $filterOptions['countries'],
            'filterTimezones' => $filterOptions['timezones'],
            'projects' => Project::query()->orderBy('name')->get(['id', 'name']),
            'draftNewsletter' => $newsletter
                ? NewsletterResource::make($newsletter)->resolve($request)
                : null,
            'defaultTemplate' => $defaultTemplate
                ? EmailTemplateResource::make($defaultTemplate)->resolve($request)
                : null,
            'initialStep' => $this->resolveWizardStep($step),
        ];
    }

    private function resolveWizardStep(string $step): string
    {
        return in_array($step, self::WIZARD_STEPS, true) ? $step : 'content';
    }

    private function getMutableNewsletter(int|string $newsletterId): Newsletter
    {
        return Newsletter::query()
            ->mutable()
            ->with(['message.template', 'messageB.template', 'emailIdentity', 'segment', 'projects:projects.id'])
            ->withMetricsCounts((int) $newsletterId)
            ->findOrFail($newsletterId);
    }
}
