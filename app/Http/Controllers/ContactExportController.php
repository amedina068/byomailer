<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\ContactAttribute;
use App\Support\SpreadsheetCell;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $user = $request->user();
        $listIds = $this->parseIntegerArray($request->input('lists'));
        $tagIds = $this->parseIntegerArray($request->input('tags'));
        $search = $request->input('search', '');

        $query = Contact::query()
            ->when(! $request->boolean('include_unsubscribed'), function ($query) {
                $query->whereNull('unsubscribed_at');
            })
            ->when(count($listIds) > 0, function ($query) use ($listIds) {
                $query->whereHas('lists', function ($subQuery) use ($listIds) {
                    $subQuery->whereIn('lists.id', $listIds);
                });
            })
            ->when(count($tagIds) > 0, function ($query) use ($tagIds) {
                $query->whereHas('tags', function ($subQuery) use ($tagIds) {
                    $subQuery->whereIn('tags.id', $tagIds);
                });
            })
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->latest('id');

        $extraAttributeNames = ContactAttribute::query()
            ->orderBy('name')
            ->pluck('name')
            ->all();

        return response()->streamDownload(function () use ($query, $extraAttributeNames) {
            $output = fopen('php://output', 'w');

            fputcsv($output, array_map(SpreadsheetCell::escapeFormula(...), array_merge(
                ['email', 'name', 'lists', 'tags', 'subscribed_at', 'unsubscribed_at', 'unsubscribed_by_contact', 'complained_at', 'bounced_at'],
                $extraAttributeNames,
            )));

            $query->chunk(200, function ($contacts) use ($output, $extraAttributeNames) {
                $contacts->load('lists', 'tags');
                $contacts->loadMax('mails as latest_complained_at', 'complained_at');
                $contacts->loadMax('mails as latest_bounced_at', 'bounced_at');

                foreach ($contacts as $contact) {
                    $listNames = $contact->lists->pluck('name')->implode(', ');
                    $tagNames = $contact->tags->pluck('name')->implode(', ');
                    $extraValues = array_map(
                        fn (string $attr) => data_get($contact->extra_attributes ?? [], $attr, ''),
                        $extraAttributeNames,
                    );

                    fputcsv($output, array_map(SpreadsheetCell::fromValue(...), array_merge([
                        $contact->email,
                        $contact->name ?? '',
                        $listNames,
                        $tagNames,
                        $contact->created_at?->toDateTimeString() ?? '',
                        $contact->unsubscribed_at?->toDateTimeString() ?? '',
                        $contact->unsubscribed_by_contact ? 'true' : 'false',
                        $contact->latest_complained_at ?? '',
                        $contact->latest_bounced_at ?? '',
                    ], $extraValues)));
                }
            });

            fclose($output);
        }, 'contacts.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function parseIntegerArray(mixed $input): array
    {
        if (is_string($input) && $input !== '') {
            return array_filter(array_map('intval', explode(',', $input)), fn ($v) => $v > 0);
        }

        if (is_array($input)) {
            return array_filter(array_map('intval', $input), fn ($v) => $v > 0);
        }

        return [];
    }
}
