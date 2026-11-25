<?php

namespace App\Http\Controllers;

use App\Http\Requests\EnrollContactsInSequenceRequest;
use App\Models\Contact;
use App\Models\Sequence;
use Illuminate\Http\RedirectResponse;
use Throwable;

class ContactSequenceEnrollmentController extends Controller
{
    public function __invoke(EnrollContactsInSequenceRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $sequence = Sequence::query()->findOrFail($data['sequence_id']);

        $contacts = Contact::query()
            ->whereIn('id', $data['contact_ids'])
            ->get();

        $alreadyEnrolled = $sequence->contacts()
            ->whereIn('contacts.id', $contacts->pluck('id'))
            ->pluck('contacts.id')
            ->all();

        $added = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($contacts as $contact) {
            if (in_array($contact->id, $alreadyEnrolled, true)) {
                $skipped++;

                continue;
            }

            try {
                $result = $contact->syncSequences([$sequence->id], true);

                if (! empty($result['attached'])) {
                    $added++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
            }
        }

        return back()->with('success', $this->summaryMessage($sequence->name, $added, $skipped, $failed));
    }

    private function summaryMessage(string $sequenceName, int $added, int $skipped, int $failed): string
    {
        $parts = [
            $added === 1
                ? "Added 1 contact to \"{$sequenceName}\"."
                : "Added {$added} contacts to \"{$sequenceName}\".",
        ];

        if ($skipped > 0) {
            $parts[] = $skipped === 1
                ? '1 was skipped (already enrolled or did not match the sequence filters).'
                : "{$skipped} were skipped (already enrolled or did not match the sequence filters).";
        }

        if ($failed > 0) {
            $parts[] = $failed === 1
                ? '1 failed.'
                : "{$failed} failed.";
        }

        return implode(' ', $parts);
    }
}
