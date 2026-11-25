<?php

namespace App\Mcp\Tools;

use App\Http\Resources\LinkClickContactResource;
use App\Models\ContactMail;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Services\LinkClickReport;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Report who clicked which link in one newsletter or one sequence step. Without "link", it lists each link with its unique contacts, total clicks and click rate (unique contacts / delivered * 100). A named link (data-bm-link attribute) groups by its name. Any other link groups by its URL without utm_* parameters. With "link" set to a key from that list, it lists the contacts who clicked that link, page by page with after_id. Clicks can include link scanners, for example Microsoft Safe Links.')]
class LinkClicksTool extends Tool
{
    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    public function __construct(private LinkClickReport $linkClickReport) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'newsletter_id' => ['required_without:step_id', 'prohibits:step_id', 'nullable', 'integer'],
            'sequence_id' => ['required_with:step_id', 'nullable', 'integer'],
            'step_id' => ['nullable', 'integer'],
            'link' => ['nullable', 'string', 'max:2048'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'after_id' => ['nullable', 'integer', 'min:0'],
        ]);

        $mailable = $this->findMailable($validated);

        if (is_string($mailable)) {
            return Response::error($mailable);
        }

        $link = $validated['link'] ?? null;

        if ($link === null) {
            return Response::structured($this->linkClickReport->summary($mailable));
        }

        if (! LinkClickReport::isValidKey($link)) {
            return Response::error('The link must be a key from the link list, for example "name:black-friday" or "url:https://example.com/sale".');
        }

        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);

        $mails = $this->linkClickReport
            ->clickers($mailable, $link)
            ->where('id', '>', (int) ($validated['after_id'] ?? 0))
            ->limit($limit + 1)
            ->get();

        $page = $mails->take($limit);

        return Response::structured([
            'link' => $link,
            'contacts' => $page->map(fn (ContactMail $mail): array => LinkClickContactResource::make($mail)->resolve())->values()->all(),
            'next_after_id' => $mails->count() > $limit ? $page->last()?->id : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function findMailable(array $validated): Newsletter|SequenceStep|string
    {
        if (isset($validated['newsletter_id'])) {
            return Newsletter::query()->find($validated['newsletter_id'])
                ?? "No newsletter found with id {$validated['newsletter_id']}.";
        }

        $sequence = Sequence::query()->find($validated['sequence_id']);

        if (! $sequence) {
            return "No sequence found with id {$validated['sequence_id']}.";
        }

        return SequenceStep::query()->where('sequence_id', $sequence->id)->find($validated['step_id'])
            ?? "No step with id {$validated['step_id']} found on sequence {$validated['sequence_id']}.";
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'newsletter_id' => $schema->integer()
                ->description('The newsletter to report on. Send this or sequence_id with step_id.'),
            'sequence_id' => $schema->integer()
                ->description('The sequence of the step. Required with step_id.'),
            'step_id' => $schema->integer()
                ->description('The sequence step to report on.'),
            'link' => $schema->string()
                ->description('A link key from the link list ("name:<link name>" or "url:<url>"). When set, the tool lists the contacts who clicked that link.'),
            'limit' => $schema->integer()
                ->description('Contacts per page when "link" is set. Default 50, maximum 200.'),
            'after_id' => $schema->integer()
                ->description('The next_after_id of the previous page.'),
        ];
    }
}
