<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExportSequenceStepContactMailsRequest;
use App\Models\SequenceStep;
use App\Services\ContactMailExport;
use App\Services\ContactMailQuery;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Group('Sequences', 'A sequence is a series of emails. Each enrolled contact gets the steps on a schedule. The API reads sequences, steps and statistics, exports the mails of a step, and changes step filters and content variables.', weight: 6)]
class SequenceStepContactMailExportController extends Controller
{
    /**
     * Export the mails of a sequence step.
     *
     * Returns a CSV file with one row for each mail of the step. The query
     * parameters filter the rows.
     */
    #[Response(200, description: 'A CSV file with one row for each mail.', mediaType: 'text/csv; charset=UTF-8', type: 'string')]
    public function __invoke(ExportSequenceStepContactMailsRequest $request, int $sequenceId, int $sequenceStepId): StreamedResponse
    {
        $step = SequenceStep::query()
            ->where('sequence_id', $sequenceId)
            ->whereHas('sequence')
            ->with(['sequence.client', 'message.emailIdentity'])
            ->findOrFail($sequenceStepId);

        $query = app(ContactMailQuery::class)->forUser(
            $request->user(),
            $request->validated(),
            $step,
        )->with('message.emailIdentity');

        $export = new ContactMailExport($step->message, $step->sequence->client);

        return response()->streamDownload(function () use ($query, $export): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ContactMailExport::columns(), escape: '');

            $query->chunk(200, function (Collection $mails) use ($output, $export): void {
                foreach ($mails as $mail) {
                    fputcsv($output, $export->row($mail), escape: '');
                }
            });

            fclose($output);
        }, "sequence-{$step->sequence_id}-step-{$step->id}-contact-mails.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
