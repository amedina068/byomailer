<?php

namespace App\Mcp\Tools;

use App\Models\Contact;
use App\Models\MailList;
use App\Models\User;
use App\Services\ContactBulkUpdater;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Description('Update up to 100 existing contacts in one call. Each item names one contact by contact_id or by exact email, and gives its own changes: name, add_tags, remove_tags, set_attributes, remove_attributes, add_to_lists and remove_from_lists. Only the fields you send change. Unknown tag names in add_tags are created. Unknown tag names in remove_tags are ignored. set_attributes merges into the custom attributes of the contact. Adding a contact to a list that requires opt-in queues the opt-in email. Adding a contact to a list never changes the subscription. Tag, list and attribute changes run the live automations of the account. Items are independent: a failed item does not stop the other items, and the result reports each item as updated or failed. Find the contacts with list-contacts and the list ids with view-newsletter-filters-tool.')]
class BulkUpdateContactsTool extends Tool
{
    private const MAX_CONTACTS = 100;

    private const RESERVED_ATTRIBUTES = ['name', 'email'];

    protected string $name = 'bulk-update-contacts';

    public function handle(Request $request, ContactBulkUpdater $updater): Response|ResponseFactory
    {
        $validated = $request->validate([
            'contacts' => ['required', 'array', 'min:1', 'max:'.self::MAX_CONTACTS],
            'contacts.*' => ['array'],
            'contacts.*.contact_id' => ['nullable', 'integer', 'required_without:contacts.*.email'],
            'contacts.*.email' => ['nullable', 'email', 'max:255', 'required_without:contacts.*.contact_id'],
            'contacts.*.name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contacts.*.add_tags' => ['sometimes', 'array', 'max:50'],
            'contacts.*.add_tags.*' => ['string', 'max:255'],
            'contacts.*.remove_tags' => ['sometimes', 'array', 'max:50'],
            'contacts.*.remove_tags.*' => ['string', 'max:255'],
            'contacts.*.set_attributes' => ['sometimes', 'array', 'max:50', $this->attributeMapRule()],
            'contacts.*.remove_attributes' => ['sometimes', 'array', 'max:50'],
            'contacts.*.remove_attributes.*' => ['string', 'max:255'],
            'contacts.*.add_to_lists' => ['sometimes', 'array', 'max:20'],
            'contacts.*.add_to_lists.*' => ['integer'],
            'contacts.*.remove_from_lists' => ['sometimes', 'array', 'max:20'],
            'contacts.*.remove_from_lists.*' => ['integer'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $items = $validated['contacts'];

        $listIds = collect($items)
            ->flatMap(fn (array $item): array => [...($item['add_to_lists'] ?? []), ...($item['remove_from_lists'] ?? [])])
            ->map(fn (mixed $id): int => (int) $id)
            ->unique();
        $lists = MailList::query()->whereIn('id', $listIds->all())->get()->keyBy('id');
        $missingListId = $listIds->first(fn (int $id): bool => ! $lists->has($id));

        if ($missingListId !== null) {
            return Response::error("No list found with id {$missingListId}. Nothing was changed.");
        }

        $contactsById = Contact::query()
            ->whereIn('id', collect($items)->pluck('contact_id')->filter()->all())
            ->get()
            ->keyBy('id');
        $contactsByEmail = Contact::query()
            ->whereIn('email', collect($items)->whereNull('contact_id')->pluck('email')->filter()->all())
            ->get()
            ->keyBy(fn (Contact $contact): string => Str::lower($contact->email));

        $results = collect($items)->map(function (array $item, int $index) use ($user, $updater, $lists, $contactsById, $contactsByEmail): array {
            $contact = isset($item['contact_id'])
                ? $contactsById->get((int) $item['contact_id'])
                : $contactsByEmail->get(Str::lower((string) $item['email']));

            $failure = $this->itemFailure($item, $contact);

            if ($failure !== null) {
                return $this->failed($index, $item, $failure);
            }

            try {
                $updated = $updater->apply(
                    $user,
                    $contact,
                    $this->changes($item),
                    $this->pickLists($lists, $item['add_to_lists'] ?? []),
                    $this->pickLists($lists, $item['remove_from_lists'] ?? []),
                );
            } catch (Throwable $exception) {
                report($exception);

                return $this->failed($index, $item, 'The update failed. The other items are not affected.');
            }

            return [
                'index' => $index,
                'status' => 'updated',
                ...$this->describe($updated),
            ];
        });

        return Response::structured([
            'updated' => $results->where('status', 'updated')->count(),
            'failed' => $results->where('status', 'failed')->count(),
            'results' => $results->values()->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'contacts' => $schema->array()
                ->min(1)
                ->max(self::MAX_CONTACTS)
                ->items($schema->object([
                    'contact_id' => $schema->integer()
                        ->description('Id of the contact. Send contact_id or email.'),
                    'email' => $schema->string()
                        ->description('Exact email of the contact. Used only when contact_id is not sent.'),
                    'name' => $schema->string()
                        ->description('New display name. Send null to clear it.'),
                    'add_tags' => $schema->array()
                        ->items($schema->string())
                        ->description('Tag names to add. Unknown names are created. Existing tags stay.'),
                    'remove_tags' => $schema->array()
                        ->items($schema->string())
                        ->description('Tag names to remove. Unknown names are ignored.'),
                    'set_attributes' => $schema->object()
                        ->description('Custom attributes to set, as {"name": value}. A value is a string (maximum 255 characters), a number or a boolean. Attributes that you do not send stay. The names "name" and "email" are reserved.'),
                    'remove_attributes' => $schema->array()
                        ->items($schema->string())
                        ->description('Custom attribute names to remove from the contact.'),
                    'add_to_lists' => $schema->array()
                        ->items($schema->integer())
                        ->description('List ids to add the contact to. A list that requires opt-in queues the opt-in email.'),
                    'remove_from_lists' => $schema->array()
                        ->items($schema->integer())
                        ->description('List ids to remove the contact from.'),
                ]))
                ->description('The contacts to update (1-'.self::MAX_CONTACTS.'). Each item has its own changes.')
                ->required(),
        ];
    }

    private function attributeMapRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value) || ($value !== [] && array_is_list($value))) {
                $fail('set_attributes must be an object of attribute names and values.');

                return;
            }

            foreach ($value as $key => $item) {
                $name = trim((string) $key);

                if ($name === '' || Str::length($name) > 255) {
                    $fail('Each attribute name must have 1 to 255 characters.');

                    return;
                }

                if (in_array(Str::lower($name), self::RESERVED_ATTRIBUTES, true)) {
                    $fail("The attribute name \"{$name}\" is reserved. Use the name field instead.");

                    return;
                }

                $isScalar = is_int($item) || is_float($item) || is_bool($item)
                    || (is_string($item) && Str::length($item) <= 255);

                if (! $isScalar) {
                    $fail("The value of attribute \"{$name}\" must be a string of maximum 255 characters, a number or a boolean.");

                    return;
                }
            }
        };
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function itemFailure(array $item, ?Contact $contact): ?string
    {
        if (! $contact) {
            return isset($item['contact_id'])
                ? "No contact found with id {$item['contact_id']}."
                : "No contact found with email {$item['email']}.";
        }

        if ($this->changes($item) === [] && empty($item['add_to_lists']) && empty($item['remove_from_lists'])) {
            return 'No changes given.';
        }

        $overlaps = [
            'add_tags and remove_tags' => array_intersect(
                array_map('trim', $item['add_tags'] ?? []),
                array_map('trim', $item['remove_tags'] ?? []),
            ),
            'set_attributes and remove_attributes' => array_intersect(
                array_map(fn (int|string $key): string => trim((string) $key), array_keys($item['set_attributes'] ?? [])),
                $item['remove_attributes'] ?? [],
            ),
            'add_to_lists and remove_from_lists' => array_intersect(
                $item['add_to_lists'] ?? [],
                $item['remove_from_lists'] ?? [],
            ),
        ];

        foreach ($overlaps as $fields => $overlap) {
            if ($overlap !== []) {
                return "The same value is in {$fields}: ".implode(', ', array_unique($overlap)).'.';
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{name?: string|null, add_tags?: array<int, string>, remove_tags?: array<int, string>, set_attributes?: array<string, string|int|float|bool>, remove_attributes?: array<int, string>}
     */
    private function changes(array $item): array
    {
        $changes = array_filter(
            array_intersect_key($item, array_flip(['add_tags', 'remove_tags', 'set_attributes', 'remove_attributes'])),
            fn (array $value): bool => $value !== [],
        );

        if (array_key_exists('name', $item)) {
            $changes['name'] = $item['name'];
        }

        if (isset($changes['set_attributes'])) {
            $changes['set_attributes'] = collect($changes['set_attributes'])
                ->mapWithKeys(fn (mixed $value, int|string $key): array => [trim((string) $key) => $value])
                ->all();
        }

        return $changes;
    }

    /**
     * @param  Collection<int, MailList>  $lists
     * @param  array<int, int|string>  $ids
     * @return Collection<int, MailList>
     */
    private function pickLists(Collection $lists, array $ids): Collection
    {
        return collect($ids)
            ->map(fn (int|string $id): int => (int) $id)
            ->unique()
            ->map(fn (int $id): MailList => $lists->get($id))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{index: int, status: string, contact_id: int|null, email: string|null, error: string}
     */
    private function failed(int $index, array $item, string $error): array
    {
        return [
            'index' => $index,
            'status' => 'failed',
            'contact_id' => isset($item['contact_id']) ? (int) $item['contact_id'] : null,
            'email' => $item['email'] ?? null,
            'error' => $error,
        ];
    }

    /**
     * @return array{contact_id: int, email: string, name: string|null, tags: list<string>, lists: list<int>, attributes: object}
     */
    private function describe(Contact $contact): array
    {
        $contact->load(['tags:tags.id,tags.name', 'lists:lists.id']);

        return [
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'name' => $contact->name,
            'tags' => $contact->tags->pluck('name')->values()->all(),
            'lists' => $contact->lists->pluck('id')->values()->all(),
            'attributes' => (object) ($contact->extra_attributes ?? []),
        ];
    }
}
