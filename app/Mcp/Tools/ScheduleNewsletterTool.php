<?php

namespace App\Mcp\Tools;

use App\Enums\NewsletterStatus;
use App\Mcp\Concerns\ConfirmsLiveEdits;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\NewsletterScheduleService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Schedule a draft newsletter to send at a future time (action "schedule"), move a scheduled newsletter to a new time (action "schedule"), or cancel the schedule and return it to draft (action "unschedule"). This affects real contacts, so the first call only returns a preview: the change, the send time and the number of recipients. Nothing is saved. Show the preview to the user, and after the user confirms, call the tool again with the same arguments and confirm_live set to true. Never set confirm_live without that confirmation. The dashboard runs the same checks before scheduling: subject and body, the SES account status, and the SES permissions. A missing {{ unsubscribe_url }} link or a missing postal address never blocks the schedule. The preview and the result list them in warnings. Show the warnings to the user. Set the sender first with update-newsletter-tool. This tool never sends a newsletter immediately.')]
class ScheduleNewsletterTool extends Tool
{
    use ConfirmsLiveEdits;

    protected string $name = 'schedule-newsletter';

    public function __construct(private readonly NewsletterScheduleService $newsletterScheduleService) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'newsletter_id' => ['required', 'integer'],
            'action' => ['required', 'in:schedule,unschedule'],
            'scheduled_at' => ['required_if:action,schedule', 'nullable', 'date', 'after:now'],
            ...$this->confirmLiveValidationRules(),
        ], [
            'scheduled_at.after' => 'The scheduled_at time must be in the future.',
        ]);

        $newsletter = Newsletter::query()->with(['message', 'messageB'])->find($validated['newsletter_id']);

        if ($newsletter === null) {
            return Response::error("No newsletter found with id {$validated['newsletter_id']}.");
        }

        if ($validated['action'] === 'unschedule') {
            return $this->unschedule($user, $newsletter, $validated);
        }

        if (! $newsletter->isMutable()) {
            return Response::error("Newsletter #{$newsletter->id} is {$newsletter->status->value}. Only draft and scheduled newsletters can be scheduled.");
        }

        if ($newsletter->message?->email_identity_id === null) {
            return Response::error("Newsletter #{$newsletter->id} has no sender. Set email_identity_id with update-newsletter-tool first.");
        }

        $check = $this->newsletterScheduleService->sendCheck($user, $newsletter);

        if ($check['errors'] !== []) {
            return Response::error("Newsletter #{$newsletter->id} cannot be scheduled: ".implode(' ', $check['errors']));
        }

        $scheduledAt = Carbon::parse($validated['scheduled_at'])->utc();
        $warnings = array_values($check['warnings']);

        if (! $this->confirmsLive($validated)) {
            return $this->statusChangePreview([
                ...$this->formatNewsletter($user, $newsletter),
                'change' => $newsletter->status === NewsletterStatus::Scheduled ? 'reschedule' : 'schedule',
                'new_status' => NewsletterStatus::Scheduled->value,
                'new_scheduled_at' => $scheduledAt->toIso8601String(),
                'new_scheduled_at_local' => $this->localTime($user, $scheduledAt),
                'recipient_count' => $check['recipient_count'],
                'warnings' => $warnings,
                'effects' => [
                    "The newsletter sends to about {$check['recipient_count']} contacts at the new time. The count is calculated again when it sends.",
                ],
            ]);
        }

        if (! $this->newsletterScheduleService->schedule($newsletter, $scheduledAt)) {
            return Response::error("Newsletter #{$newsletter->id} started to send. It is {$newsletter->refresh()->status->value} now and cannot be scheduled.");
        }

        return Response::structured([
            ...$this->formatNewsletter($user, $newsletter->refresh()),
            'recipient_count' => $check['recipient_count'],
            'warnings' => $warnings,
            'applied' => true,
            'message' => "Newsletter #{$newsletter->id} scheduled.",
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function unschedule(User $user, Newsletter $newsletter, array $validated): Response|ResponseFactory
    {
        if ($newsletter->status === NewsletterStatus::Draft) {
            return Response::structured([
                ...$this->formatNewsletter($user, $newsletter),
                'applied' => false,
                'message' => "Newsletter #{$newsletter->id} is already a draft. Nothing changed.",
            ]);
        }

        if ($newsletter->status !== NewsletterStatus::Scheduled) {
            return Response::error("Newsletter #{$newsletter->id} is {$newsletter->status->value}. Only a scheduled newsletter can be unscheduled.");
        }

        if (! $this->confirmsLive($validated)) {
            $recipientCount = $this->newsletterScheduleService->recipientCount($user, $newsletter);

            return $this->statusChangePreview([
                ...$this->formatNewsletter($user, $newsletter),
                'change' => 'unschedule',
                'new_status' => NewsletterStatus::Draft->value,
                'recipient_count' => $recipientCount,
                'effects' => [
                    "The newsletter does not send. About {$recipientCount} contacts do not receive it until it is scheduled again.",
                ],
            ]);
        }

        if (! $this->newsletterScheduleService->unschedule($newsletter)) {
            return Response::error("Newsletter #{$newsletter->id} started to send. It is {$newsletter->refresh()->status->value} now and cannot return to draft.");
        }

        return Response::structured([
            ...$this->formatNewsletter($user, $newsletter->refresh()),
            'applied' => true,
            'message' => "Newsletter #{$newsletter->id} is a draft again.",
        ]);
    }

    /**
     * @return array{id: int, title: ?string, subject: ?string, status: string, scheduled_at: ?string, scheduled_at_local: ?string}
     */
    private function formatNewsletter(User $user, Newsletter $newsletter): array
    {
        return [
            'id' => $newsletter->id,
            'title' => $newsletter->title,
            'subject' => $newsletter->message?->subject,
            'status' => $newsletter->status->value,
            'scheduled_at' => $newsletter->scheduled_at?->toIso8601String(),
            'scheduled_at_local' => $newsletter->scheduled_at === null ? null : $this->localTime($user, $newsletter->scheduled_at),
        ];
    }

    private function localTime(User $user, CarbonInterface $time): string
    {
        $timezone = $user->client?->timezone ?? 'UTC';

        return $time->copy()->timezone($timezone)->format('Y-m-d H:i').' '.$timezone;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'newsletter_id' => $schema->integer()
                ->description('Id of the newsletter. Use list-newsletters-tool to discover ids.')
                ->required(),
            'action' => $schema->string()
                ->enum(['schedule', 'unschedule'])
                ->description('"schedule" sets or moves the send time. "unschedule" returns a scheduled newsletter to draft.')
                ->required(),
            'scheduled_at' => $schema->string()
                ->description('Required for "schedule". The send time in ISO 8601 with a UTC offset, for example 2026-11-02T09:00:00-05:00. It must be in the future.'),
            ...$this->confirmLiveSchema($schema),
        ];
    }
}
