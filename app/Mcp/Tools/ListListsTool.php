<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\FormatsMailLists;
use App\Models\MailList;
use App\Models\Topic;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the mailing lists of the account, newest first. Each list has its id, name, opt-in setting, opt-in email, contact counts and topics. contacts_count counts all contacts on the list. confirmed_contacts_count counts the contacts that newsletters, sequences and automations select through the list. On a list with opt-in, only the contacts that confirmed the opt-in email count. Each topic has its id, name, description and the number of subscribed contacts. Use the ids with update-list, create-topic, update-topic, delete-topic and add-contact-to-list.')]
class ListListsTool extends Tool
{
    use FormatsMailLists;

    protected string $name = 'list-lists';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));

        $lists = MailList::query()
            ->with([
                'message',
                'topics' => fn (HasMany $query) => $query
                    ->withCount(['contacts as subscribed_count' => fn (Builder $query) => $query->whereNull('contact_topic.unsubscribed_at')])
                    ->orderBy('id'),
            ])
            ->withCount([
                'contacts',
                'contacts as opted_in_contacts_count' => fn (Builder $query) => $query->whereNotNull('contact_list.opted_in_at'),
            ])
            ->when($search !== '', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"))
            ->latest('id')
            ->limit((int) ($validated['limit'] ?? 25))
            ->get()
            ->map(fn (MailList $list): array => [
                ...$this->formatMailList($list),
                'contacts_count' => (int) $list->contacts_count,
                'confirmed_contacts_count' => (int) ($list->opt_in_enabled ? $list->getAttribute('opted_in_contacts_count') : $list->contacts_count),
                'topics' => $list->topics
                    ->map(fn (Topic $topic): array => [
                        ...$this->formatTopic($topic),
                        'subscribed_count' => (int) $topic->getAttribute('subscribed_count'),
                    ])
                    ->all(),
            ])
            ->all();

        return Response::structured([
            'lists' => $lists,
            'count' => count($lists),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()
                ->description('Optional search in the list name. The search ignores case.'),
            'limit' => $schema->integer()
                ->description('Maximum number of lists to return (1-100, default 25).'),
        ];
    }
}
