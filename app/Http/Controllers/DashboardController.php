<?php

namespace App\Http\Controllers;

use App\Enums\IdentityStatus;
use App\Enums\NewsletterStatus;
use App\Enums\SequenceStatus;
use App\Models\Client;
use App\Models\ContactMailClick;
use App\Models\EmailIdentity;
use App\Services\SesPermissionChecker;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $client = $user->client;

        $emailCredential = $client->sesCredential()->withoutGlobalScopes()->first();
        $hasCredentials = $emailCredential !== null;
        $hasVerifiedIdentity = $client->emailIdentities()->withoutGlobalScopes()
            ->where('identity_status', IdentityStatus::Verified)
            ->exists();
        $pendingIdentityId = $hasVerifiedIdentity ? null : EmailIdentity::query()->latest('id')->value('id');

        $sevenDaysAgo = Carbon::today()->subDays(6)->startOfDay();
        $monthStart = Carbon::now()->startOfMonth();

        $stats = $client->contactMails()->withoutGlobalScopes()
            ->selectRaw(implode(', ', [
                'COUNT(CASE WHEN sent_at >= ? THEN 1 END) as monthly_sent',
                'COUNT(CASE WHEN sent_at >= ? THEN 1 END) as weekly_sent',
                'COUNT(CASE WHEN delivered_at >= ? THEN 1 END) as weekly_delivered',
                'COUNT(CASE WHEN unsubscribed_at >= ? THEN 1 END) as weekly_unsubscribed',
                'COUNT(CASE WHEN bounced_at >= ? THEN 1 END) as weekly_bounced',
            ]))
            ->addBinding([$monthStart, $sevenDaysAgo, $sevenDaysAgo, $sevenDaysAgo, $sevenDaysAgo], 'select')
            ->toBase()
            ->first();

        $monthlySesCost = [
            'emails_sent' => (int) $stats->monthly_sent,
            'estimated_cost' => round((int) $stats->monthly_sent * 0.0001, 2),
        ];

        $weeklyStats = [
            'sent' => (int) $stats->weekly_sent,
            'delivered' => (int) $stats->weekly_delivered,
            'unsubscribed' => (int) $stats->weekly_unsubscribed,
            'clicked' => ContactMailClick::query()
                ->join('contact_mails', 'contact_mails.id', '=', 'contact_mail_clicks.contact_mail_id')
                ->where('contact_mails.client_id', $client->id)
                ->where('contact_mail_clicks.clicked_at', '>=', $sevenDaysAgo)
                ->distinct('contact_mail_clicks.contact_mail_id')
                ->count('contact_mail_clicks.contact_mail_id'),
            'bounced' => (int) $stats->weekly_bounced,
        ];

        $checker = app(SesPermissionChecker::class);
        $allRequired = array_merge(
            SesPermissionChecker::NEWSLETTER_BULK,
            SesPermissionChecker::SEND_EMAIL,
        );

        return Inertia::render('Dashboard', [
            'hasCredentials' => $hasCredentials,
            'hasVerifiedIdentity' => $hasVerifiedIdentity,
            'pendingIdentityId' => $pendingIdentityId,
            'missingPermissions' => $checker->getMissing($user, $allRequired, $emailCredential),
            'sesQuota' => $emailCredential ? [
                'max_24_hour_send' => $emailCredential->max_24_hour_send,
                'max_send_rate' => $emailCredential->max_send_rate,
                'sent_last_24_hours' => $emailCredential->sent_last_24_hours,
            ] : null,
            'monthlySesCost' => $monthlySesCost,
            'weeklyStats' => $weeklyStats,
            'contactGrowth' => Inertia::defer(fn () => $this->buildContactGrowthChart($client, $sevenDaysAgo)),
            'latestNewsletters' => Inertia::defer(fn () => $this->buildLatestNewsletters($client)),
            'newsletterStatuses' => NewsletterStatus::options(),
            'sequenceStatuses' => SequenceStatus::options(),
            'recentSequences' => Inertia::defer(fn () => $this->buildRecentSequences($client), 'secondary'),
            'recentContacts' => Inertia::defer(fn () => $this->buildRecentContacts($client), 'secondary'),
        ]);
    }

    /**
     * @return array<int, array{id: int, subject: string|null, status: string, sent_at: string|null, created_at: string|null, metrics: array}>
     */
    private function buildLatestNewsletters(Client $client): array
    {
        return $client->newsletters()
            ->where('status', NewsletterStatus::Sent)
            ->latest('id')
            ->limit(5)
            ->with('message')
            ->withCount([
                'contactMails as sent_count' => fn (Builder $query): Builder => $query->whereNotNull('sent_at'),
                'contactMails as pending_count' => fn (Builder $query): Builder => $query->where('status', 'pending'),
                'contactMails as opened_count' => fn (Builder $query): Builder => $query->whereNotNull('opened_at'),
                'contactMails as clicked_count' => fn (Builder $query): Builder => $query->whereNotNull('clicked_at'),
                'contactMails as bounced_count' => fn (Builder $query): Builder => $query->whereNotNull('bounced_at'),
                'contactMails as unsubscribed_count' => fn (Builder $query): Builder => $query->whereNotNull('unsubscribed_at'),
                'contactMails as complained_count' => fn (Builder $query): Builder => $query->whereNotNull('complained_at'),
            ])
            ->get(['id', 'status', 'sent_at', 'created_at'])
            ->map(function ($newsletter) {
                $sent = (int) ($newsletter->sent_count ?? 0);
                $pending = (int) ($newsletter->pending_count ?? 0);
                $opened = (int) ($newsletter->opened_count ?? 0);
                $clicked = (int) ($newsletter->clicked_count ?? 0);
                $bounced = (int) ($newsletter->bounced_count ?? 0);
                $unsubscribed = (int) ($newsletter->unsubscribed_count ?? 0);
                $complained = (int) ($newsletter->complained_count ?? 0);
                $total = $sent + $pending + $bounced + $unsubscribed + $complained;

                return [
                    'id' => $newsletter->id,
                    'subject' => $newsletter->message?->subject,
                    'status' => $newsletter->status->value,
                    'sent_at' => optional($newsletter->sent_at)->toIso8601String(),
                    'created_at' => optional($newsletter->created_at)->toIso8601String(),
                    'metrics' => [
                        'total' => $total,
                        'sent' => $sent,
                        'pending' => $pending,
                        'opened' => $opened,
                        'clicked' => $clicked,
                        'bounced' => $bounced,
                        'unsubscribed' => $unsubscribed,
                        'complained' => $complained,
                        'open_rate' => $sent > 0 ? round(($opened / $sent) * 100, 1) : null,
                        'click_rate' => $sent > 0 ? round(($clicked / $sent) * 100, 1) : null,
                        'unsubscribe_rate' => $sent > 0 ? round(($unsubscribed / $sent) * 100, 1) : null,
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: int, name: string, status: string, steps_count: int, active_count: int, completed_count: int, created_at: string|null}>
     */
    private function buildRecentSequences(Client $client): array
    {
        return $client->sequences()
            ->where('status', SequenceStatus::Live)
            ->latest('id')
            ->limit(5)
            ->withCount([
                'steps',
                'contacts',
                'contacts as completed_count' => fn (Builder $query): Builder => $query
                    ->whereNotNull('contact_sequence.completed_at'),
            ])
            ->get(['id', 'name', 'status', 'created_at'])
            ->map(function ($sequence) {
                $contacts = (int) ($sequence->contacts_count ?? 0);
                $completed = (int) ($sequence->completed_count ?? 0);

                return [
                    'id' => $sequence->id,
                    'name' => $sequence->name,
                    'status' => $sequence->status->value,
                    'steps_count' => (int) ($sequence->steps_count ?? 0),
                    'active_count' => max($contacts - $completed, 0),
                    'completed_count' => $completed,
                    'created_at' => optional($sequence->created_at)->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: int, name: string|null, email: string, unsubscribed_at: string|null, created_at: string|null}>
     */
    private function buildRecentContacts(Client $client): array
    {
        return $client->contacts()
            ->latest('id')
            ->limit(20)
            ->get(['id', 'name', 'email', 'unsubscribed_at', 'created_at'])
            ->map(fn ($contact) => [
                'id' => $contact->id,
                'name' => $contact->name,
                'email' => $contact->email,
                'unsubscribed_at' => optional($contact->unsubscribed_at)->toIso8601String(),
                'created_at' => optional($contact->created_at)->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{day: string, new: int, unsubscribed: int, net: int}>
     */
    private function buildContactGrowthChart(Client $client, Carbon $startDate): array
    {
        $today = Carbon::today();

        $newByDay = $client->contacts()
            ->where('created_at', '>=', $startDate)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupByRaw('DATE(created_at)')
            ->pluck('count', 'date');

        $unsubsByDay = $client->contacts()
            ->whereNotNull('unsubscribed_at')
            ->where('unsubscribed_at', '>=', $startDate)
            ->selectRaw('DATE(unsubscribed_at) as date, COUNT(*) as count')
            ->groupByRaw('DATE(unsubscribed_at)')
            ->pluck('count', 'date');

        $chart = [];

        for ($day = $startDate->copy(); $day->lte($today); $day->addDay()) {
            $dateKey = $day->toDateString();
            $new = (int) ($newByDay[$dateKey] ?? 0);
            $unsubs = (int) ($unsubsByDay[$dateKey] ?? 0);

            $chart[] = [
                'day' => $day->format('D j'),
                'new' => $new,
                'unsubscribed' => $unsubs,
                'net' => $new - $unsubs,
            ];
        }

        return $chart;
    }
}
