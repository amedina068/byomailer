<?php

namespace App\Http\Controllers\Api;

use App\Actions\DeleteNewsletter;
use App\Actions\UpdateNewsletterContent;
use App\Actions\UpdateNewsletterLabels;
use App\Enums\NewsletterStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreNewsletterRequest;
use App\Http\Requests\Api\UpdateNewsletterRequest;
use App\Http\Resources\Api\NewsletterResource;
use App\Models\Newsletter;
use App\Services\NewsletterScheduleService;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

#[Group('Newsletters', 'A newsletter is one email to an audience of contacts. Create and edit drafts, schedule a send, pick the winner of an A/B test, and send a test email to yourself.', weight: 5)]
class NewsletterController extends Controller
{
    public function __construct(
        private readonly NewsletterScheduleService $newsletterScheduleService,
    ) {}

    /**
     * List newsletters.
     *
     * Returns the newsletters of your client, newest first, with their send
     * metrics. Filter by status, by project, or by a text in the title or the
     * subject.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', 'string', Rule::enum(NewsletterStatus::class)],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'project' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
            'cursor' => ['sometimes', 'nullable', 'string'],
        ]);

        $search = trim((string) $request->input('search', ''));

        $newsletters = Newsletter::query()
            ->with(['message.emailIdentity', 'messageB', 'segment', 'projects:projects.id'])
            ->withMetricsCounts()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->when($request->filled('project'), fn ($query) => $query->whereHas(
                'projects',
                fn ($projectQuery) => $projectQuery->where('projects.id', (int) $request->input('project')),
            ))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhereHas('message', fn ($mq) => $mq->where('subject', 'like', "%{$search}%"));
                });
            })
            ->latest('id')
            ->cursorPaginate(max(1, min(100, (int) $request->input('per_page', 25))));

        return NewsletterResource::summaryCollection($newsletters);
    }

    /**
     * Get a newsletter.
     *
     * Returns one newsletter with its message, its A/B variant, its filters, its
     * projects and its send metrics.
     */
    public function show(Request $request, int $newsletterId): JsonResponse
    {
        $newsletter = Newsletter::query()
            ->with(['message.emailIdentity', 'messageB', 'segment', 'projects:projects.id'])
            ->withMetricsCounts($newsletterId)
            ->findOrFail($newsletterId);

        return NewsletterResource::make($newsletter)->response();
    }

    /**
     * Create a newsletter.
     *
     * Creates a draft newsletter. To schedule it, send `status: live` with
     * `scheduled_at`, `subject`, `html_content` and a verified
     * `email_identity_id`. A `scheduled_at` in the past or now queues the send at
     * once. The send checks the content, the sender and the sending permissions
     * of your AWS account first. `meta.warnings` lists the compliance warnings of
     * a scheduled or queued newsletter. They never block the send.
     */
    public function store(StoreNewsletterRequest $request, UpdateNewsletterContent $updateNewsletterContent): JsonResponse
    {
        [$newsletter, $warnings] = DB::transaction(function () use ($request, $updateNewsletterContent): array {
            $newsletter = Newsletter::create([
                'user_id' => $request->user()->id,
                'title' => $request->validated('title') ?? Newsletter::DEFAULT_TITLE,
                'status' => NewsletterStatus::Draft,
                'filters' => [],
            ]);

            $updateNewsletterContent->handle($request->user(), $newsletter, $this->contentChanges($request));

            return [$newsletter, $this->scheduleWhenLive($request, $newsletter)];
        });

        return $this->newsletterResponse($newsletter, $warnings)->setStatusCode(201);
    }

    /**
     * Update a newsletter.
     *
     * Changes a newsletter with the status `draft` or `scheduled`. Only the fields
     * in the request change. A newsletter that is sending or sent cannot change
     * and returns 422, except for a request with only `title` and `project_ids`.
     * These labels change in any status. A change to a scheduled newsletter must keep a subject and
     * a body. `status: live` schedules the newsletter, with the same checks as
     * create. `status: draft` returns a scheduled newsletter to draft and clears
     * `scheduled_at`. When the send started first, it returns 422 and nothing
     * changes.
     */
    public function update(UpdateNewsletterRequest $request, UpdateNewsletterContent $updateNewsletterContent, UpdateNewsletterLabels $updateNewsletterLabels, int $newsletterId): JsonResponse
    {
        $newsletter = Newsletter::query()->findOrFail($newsletterId);

        if (array_diff(array_keys($request->validated()), UpdateNewsletterLabels::FIELDS) === []) {
            $updateNewsletterLabels->handle($newsletter, $request->validated());

            return $this->newsletterResponse($newsletter, []);
        }

        $this->assertMutable($newsletter);

        $warnings = DB::transaction(function () use ($request, $updateNewsletterContent, $newsletter): array {
            if ($this->unschedules($request)) {
                $this->unschedule($newsletter);
            }

            $updateNewsletterContent->handle($request->user(), $newsletter, $this->contentChanges($request));

            return $this->scheduleWhenLive($request, $newsletter);
        });

        return $this->newsletterResponse($newsletter, $warnings);
    }

    /**
     * Delete a newsletter.
     *
     * Deletes a newsletter with the status `draft` or `scheduled`, with its
     * messages. Other statuses return 422. When the send of a scheduled
     * newsletter started first, it returns 422 and nothing changes.
     */
    #[Response(422, description: 'The newsletter is sending or sent, or its send started first (`status`).', type: 'array{message: string, errors: array{status: list<string>}}')]
    public function destroy(DeleteNewsletter $deleteNewsletter, int $newsletterId): JsonResponse
    {
        $deleteNewsletter->handle(Newsletter::query()->findOrFail($newsletterId));

        return response()->json(null, 204);
    }

    /**
     * Return the newsletter to draft and clear its send time. When the
     * scheduler queued it first, the request fails and the transaction rolls
     * back the other changes.
     *
     * @throws ValidationException
     */
    private function unschedule(Newsletter $newsletter): void
    {
        if ($this->newsletterScheduleService->unschedule($newsletter)) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => [NewsletterScheduleService::UNSCHEDULE_FAILED],
        ]);
    }

    private function assertMutable(Newsletter $newsletter): void
    {
        if ($newsletter->isMutable()) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => [
                "Newsletters with status '{$newsletter->status->value}' cannot be modified. Only 'draft' and 'scheduled' newsletters are mutable.",
            ],
        ]);
    }

    /**
     * `status: draft` returns the newsletter to draft. A `scheduled_at` of
     * null without a status does the same, so a scheduled newsletter never
     * keeps the status `scheduled` with no send time.
     */
    private function unschedules(FormRequest $request): bool
    {
        if ($request->has('status')) {
            return $request->validated('status') === 'draft';
        }

        return $request->has('scheduled_at') && $request->validated('scheduled_at') === null;
    }

    /**
     * Schedule or queue the newsletter when the request sends `status: live`.
     * Call it last in the transaction, so a failed check rolls back the other
     * changes of the request.
     *
     * @return list<string> the compliance warnings
     */
    private function scheduleWhenLive(FormRequest $request, Newsletter $newsletter): array
    {
        if ($request->validated('status') !== 'live') {
            return [];
        }

        return $this->newsletterScheduleService->scheduleOrSend(
            $request->user(),
            $newsletter->load(['message.emailIdentity', 'messageB', 'segment']),
            Carbon::parse($request->validated('scheduled_at')),
        );
    }

    /**
     * @param  list<string>  $warnings
     */
    private function newsletterResponse(Newsletter $newsletter, array $warnings): JsonResponse
    {
        return NewsletterResource::make($newsletter->load(['message.emailIdentity', 'messageB', 'projects:projects.id']))
            ->additional(['meta' => ['warnings' => $warnings]])
            ->response();
    }

    /**
     * The content fields of the request. A field that the request leaves out
     * does not change.
     *
     * @return array<string, mixed>
     */
    private function contentChanges(FormRequest $request): array
    {
        return Arr::only($request->validated(), UpdateNewsletterContent::FIELDS);
    }
}
