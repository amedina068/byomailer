<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportContactsRequest;
use App\Imports\MailListContactsImport;
use App\Models\MailList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class MailListImportController extends Controller
{
    public function __invoke(ImportContactsRequest $request): RedirectResponse
    {
        $user = $request->user();

        $mailList = MailList::query()
            ->findOrFail($request->list_id);

        $file = $request->file('file');
        $originalFilename = $file?->getClientOriginalName() ?: 'contacts-import.csv';

        $import = new MailListContactsImport($user, $mailList, $originalFilename);

        Excel::import($import, $file);

        if ($import->problem() !== null) {
            throw ValidationException::withMessages(['file' => $import->problem()]);
        }

        return to_route('lists.show', $mailList)
            ->with('success', $this->startedMessage($import, $mailList));
    }

    private function startedMessage(MailListContactsImport $import, MailList $mailList): string
    {
        $queued = $import->queuedCount();
        $message = "Importing {$queued} ".Str::plural('contact', $queued)." into {$mailList->name}.";

        $skipped = $import->skippedCount();

        if ($skipped > 0) {
            $message .= " {$skipped} ".Str::plural('row', $skipped).' '.($skipped === 1 ? 'was' : 'were').' skipped because the email is not valid.';
        }

        return $message;
    }
}
