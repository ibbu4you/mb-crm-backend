<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Period + KPI helpers for the Field Sales dashboards. Ports the D/W/M/Y toggle
 * and KPI definitions from MB Sales Management.
 */
class Metrics
{
    public const PERIODS = ['daily', 'weekly', 'monthly', 'yearly'];

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} [start, end] */
    public static function range(string $period): array
    {
        $now = CarbonImmutable::now();

        return match ($period) {
            'daily' => [$now->startOfDay(), $now->endOfDay()],
            'weekly' => [$now->startOfWeek(), $now->endOfWeek()],
            'yearly' => [$now->startOfYear(), $now->endOfYear()],
            default => [$now->startOfMonth(), $now->endOfMonth()],
        };
    }

    /** Points for a trend chart across the period (labels + counts by day/month). */
    public static function trendBuckets(string $period): array
    {
        $now = CarbonImmutable::now();

        return match ($period) {
            'daily' => self::days($now->startOfWeek(), 7),        // last 7 days context
            'weekly' => self::days($now->startOfWeek(), 7),
            'yearly' => self::months($now->startOfYear(), 12),
            default => self::days($now->startOfMonth(), (int) $now->daysInMonth),
        };
    }

    private static function days(CarbonImmutable $start, int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $d = $start->addDays($i);
            $out[] = ['key' => $d->toDateString(), 'label' => $d->format('d M')];
        }

        return $out;
    }

    private static function months(CarbonImmutable $start, int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $d = $start->addMonths($i);
            $out[] = ['key' => $d->format('Y-m'), 'label' => $d->format('M')];
        }

        return $out;
    }
}
