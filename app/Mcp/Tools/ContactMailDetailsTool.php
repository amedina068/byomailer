<?php

namespace App\Mcp\Tools;

use App\Http\Resources\ContactMailSummaryResource;
use App\Models\ContactMailClick;
use App\Services\ContactMailQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Show one contact email, including recipient, delivery and engagement timestamps, failure reason, template data, message content and the latest 100 clicks. Find contact_mail_id with list-contact-mails-tool or list-sequence-step-contact-mails-tool. Message content is the stored template, not a snapshot of the rendered sent email.')]
class ContactMailDetailsTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate(['contact_mail_id' => ['required', 'integer', 'min:1']]);

        $mail = app(ContactMailQuery::class)->forUser($request->user(), [])
            ->with(['message', 'mailable.message', 'clicks' => fn (HasMany $query): HasMany => $query->orderByDesc('clicked_at')->orderByDesc('id')->limit(100)])
            ->withCount('clicks')
            ->find($validated['contact_mail_id']);

        if (! $mail) {
            return Response::error("No contact mail found with id {$validated['contact_mail_id']}.");
        }

        $message = $mail->message ?? $mail->mailable?->message;

        return Response::structured([
            ...ContactMailSummaryResource::make($mail)->resolve(),
            'sequence_id' => $mail->sequenceStep?->sequence_id,
            'html_content' => $message?->html_content,
            'text_content' => $message?->text_content,
            'template_data' => $mail->template_data ?? [],
            'clicks_count' => $mail->clicks_count,
            'clicks' => $mail->clicks->map(fn (ContactMailClick $click): array => [
                'url' => $click->link,
                'clicked_at' => $click->clicked_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return ['contact_mail_id' => $schema->integer()->description('The contact mail id to inspect.')->required()];
    }
}
