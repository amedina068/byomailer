<?php

namespace App\Mcp\Concerns;

use App\Http\Resources\ContactMailSummaryResource;
use App\Models\ContactMail;
use App\Models\SequenceStep;
use App\Services\ContactMailQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

trait ListsContactMails
{
    /** @return array<string, mixed> */
    protected function mailSchema(JsonSchema $schema, bool $stepScoped = false, bool $paginated = true): array
    {
        $properties = [
            'contact_id' => $schema->integer()->description('Only mails for this contact.'),
            'status' => $schema->string()->enum(ContactMailQuery::STATUSES)->description('Exact stored delivery status.'),
            'filter' => $schema->string()->enum(ContactMailQuery::FILTERS)->description('Delivery or engagement event filter, default all. Events match timestamps, so opened also includes mails later clicked or unsubscribed.'),
            'search' => $schema->string()->description('Search the contact email, name, or mail subject.'),
            'sent_after' => $schema->string()->description('Inclusive earliest sent timestamp (ISO 8601).'),
            'sent_before' => $schema->string()->description('Inclusive latest sent timestamp (ISO 8601).'),
            'sort' => $schema->string()->enum(ContactMailQuery::SORT_COLUMNS)->description('Sort column, default id. Ties are ordered by mail id.'),
            'direction' => $schema->string()->enum(['asc', 'desc'])->description('Sort direction, default desc.'),
        ];

        if ($stepScoped) {
            $properties['sequence_id'] = $schema->integer()->description('The sequence id.')->required();
            $properties['step_id'] = $schema->integer()->description('The step id belonging to this sequence.')->required();
            $properties['current_only'] = $schema->boolean()->description('Default true: contacts currently at this step, matching step stats. Set false for all historical mails, including completed or advanced contacts.');
        } else {
            $properties['newsletter_id'] = $schema->integer()->description('Only mails for this newsletter.');
            $properties['sequence_id'] = $schema->integer()->description('Only mails across this sequence.');
            $properties['step_id'] = $schema->integer()->description('Only historical mails for this sequence step.');
        }

        if ($paginated) {
            $properties['page'] = $schema->integer()->description('Page number (minimum 1, default 1).');
            $properties['per_page'] = $schema->integer()->description('Page size (1-100, default 25).');
        }

        return $properties;
    }

    /** @return array<string, mixed> */
    protected function validateMailFilters(Request $request, bool $stepScoped = false): array
    {
        $rules = ContactMailQuery::rules();

        if ($stepScoped) {
            unset($rules['newsletter_id']);
            $rules['sequence_id'] = ['required', 'integer', 'min:1'];
            $rules['step_id'] = ['required', 'integer', 'min:1'];
            $rules['current_only'] = ['sometimes', 'boolean'];
        }

        return $request->validate($rules);
    }

    /** @param array<string, mixed> $filters */
    protected function resolveStep(array $filters): ?SequenceStep
    {
        return SequenceStep::query()
            ->where('sequence_id', $filters['sequence_id'])
            ->whereHas('sequence')
            ->find($filters['step_id']);
    }

    /** @param array<string, mixed> $filters */
    protected function mailListResponse(Request $request, array $filters, ?SequenceStep $step = null): Response|ResponseFactory
    {
        $mails = app(ContactMailQuery::class)
            ->forUser($request->user(), $filters, $step, (bool) ($filters['current_only'] ?? true))
            ->paginate((int) ($filters['per_page'] ?? 25), ['*'], 'page', (int) ($filters['page'] ?? 1));

        return Response::structured([
            'mails' => $mails->getCollection()->map(fn (ContactMail $mail): array => ContactMailSummaryResource::make($mail)->resolve())->all(),
            'pagination' => [
                'current_page' => $mails->currentPage(),
                'per_page' => $mails->perPage(),
                'total' => $mails->total(),
                'last_page' => $mails->lastPage(),
                'from' => $mails->firstItem(),
                'to' => $mails->lastItem(),
                'has_more_pages' => $mails->hasMorePages(),
            ],
        ]);
    }
}
