<?php

namespace App\Http\Controllers;

use App\Models\Newsletter;
use App\Services\NewsletterMailExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NewsletterMailExportController extends Controller
{
    /**
     * Stream one CSV row per newsletter recipient with the delivery status,
     * the recipient, and the personalized plain-text body.
     */
    public function __invoke(Request $request, string $newsletterId): StreamedResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        $newsletter = Newsletter::query()
            ->with(['client', 'message.emailIdentity'])
            ->findOrFail($newsletterId);

        $status = $validated['status'] ?? 'all';

        $query = $newsletter->contactMails()
            ->with(['contact', 'message.emailIdentity'])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status));

        $export = new NewsletterMailExport($newsletter, $newsletter->client);

        return response()->streamDownload(function () use ($query, $export): void {
            $output = fopen('php://output', 'w');

            fputcsv($output, NewsletterMailExport::columns());

            $query->chunkById(200, function ($contactMails) use ($output, $export): void {
                foreach ($contactMails as $contactMail) {
                    fputcsv($output, $export->row($contactMail));
                }
            });

            fclose($output);
        }, "newsletter-{$newsletter->id}-mails.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
