<?php

namespace App\Http\Controllers;

use App\Models\ContactMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class ReputationController extends Controller
{
    /**
     * Window key => hours back from now.
     */
    private const WINDOWS = ['24h' => 24, '7d' => 168, '30d' => 720];

    /**
     * Rates in percent. SES starts a review at the review level and can pause the account at the pause level.
     */
    public const THRESHOLDS = ['bounce_review' => 5, 'bounce_pause' => 10, 'complaint_review' => 0.1, 'complaint_pause' => 0.5];

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $credential = $user->sesCredential;

        return Inertia::render('Reputation', [
            'sesCredential' => $credential === null ? null : $credential->only(['enforcement_status', 'sending_enabled', 'max_24_hour_send', 'max_send_rate', 'sent_last_24_hours'])
                + ['quota_fetched_at' => $credential->quota_fetched_at?->toIso8601String()],
            'thresholds' => self::THRESHOLDS,
            'rates' => Inertia::defer(fn (): array => Cache::remember(
                "reputation-rates:{$user->client_id}",
                60,
                fn (): array => $this->rates($user->client_id),
            )),
        ]);
    }

    /**
     * Counts per window from one query over the last 30 days. Rates are percent, null when nothing was sent.
     * Warn is true when a 24h rate reaches its SES review level.
     *
     * @return array{warn: bool, windows: list<array{key: string, sent: int, bounced: int, complained: int, bounce_rate: float|null, complaint_rate: float|null, bounce_level: string|null, complaint_level: string|null}>}
     */
    private function rates(int $clientId): array
    {
        $starts = array_map(fn (int $hours) => now()->subHours($hours), self::WINDOWS);
        $selects = [];

        foreach (array_keys($starts) as $key) {
            $selects[] = "COUNT(CASE WHEN sent_at >= ? THEN 1 END) as sent_{$key}";
            $selects[] = "COUNT(CASE WHEN sent_at >= ? AND bounced_at IS NOT NULL THEN 1 END) as bounced_{$key}";
            $selects[] = "COUNT(CASE WHEN sent_at >= ? AND complained_at IS NOT NULL THEN 1 END) as complained_{$key}";
        }

        $counts = ContactMail::withoutGlobalScopes()
            ->where('client_id', $clientId)
            ->where('sent_at', '>=', $starts['30d'])
            ->selectRaw(implode(', ', $selects), array_merge(...array_map(fn ($start) => [$start, $start, $start], array_values($starts))))
            ->toBase()
            ->first();

        $windows = array_map(function (string $key) use ($counts): array {
            [$sent, $bounced, $complained] = [(int) $counts->{"sent_{$key}"}, (int) $counts->{"bounced_{$key}"}, (int) $counts->{"complained_{$key}"}];

            return [
                'key' => $key,
                'sent' => $sent,
                'bounced' => $bounced,
                'complained' => $complained,
                'bounce_rate' => $sent === 0 ? null : round($bounced / $sent * 100, 2),
                'complaint_rate' => $sent === 0 ? null : round($complained / $sent * 100, 3),
                'bounce_level' => $this->level($bounced, $sent, 'bounce'),
                'complaint_level' => $this->level($complained, $sent, 'complaint'),
            ];
        }, array_keys(self::WINDOWS));

        $day = $windows[0];
        $warn = in_array($day['bounce_level'], ['review', 'pause'], true)
            || in_array($day['complaint_level'], ['review', 'pause'], true);

        return ['warn' => $warn, 'windows' => $windows];
    }

    /**
     * Threshold level from the unrounded ratio, so the page color and `warn` always agree.
     */
    private function level(int $count, int $sent, string $metric): ?string
    {
        if ($sent === 0) {
            return null;
        }

        $percent = $count / $sent * 100;

        return match (true) {
            $percent >= self::THRESHOLDS["{$metric}_pause"] => 'pause',
            $percent >= self::THRESHOLDS["{$metric}_review"] => 'review',
            default => 'ok',
        };
    }
}
