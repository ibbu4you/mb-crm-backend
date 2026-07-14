<?php

namespace App\Support;

/**
 * The unified lead pipeline — single source of truth for stages, labels, colors
 * and ranks. Merges the MB Leads statuses and the MB Sales funnel:
 *   intake → cold → warm → qualified → opportunity → proposal → won / lost
 */
class Pipeline
{
    /** Open stages, in order. */
    public const STAGES = ['intake', 'cold', 'warm', 'qualified', 'opportunity', 'proposal'];

    /** Terminal outcomes. */
    public const OUTCOMES = ['won', 'lost'];

    public const META = [
        'intake' => ['label' => 'Intake', 'color' => '#94A3B8', 'rank' => 0],
        'cold' => ['label' => 'Cold', 'color' => '#60A5FA', 'rank' => 1],
        'warm' => ['label' => 'Warm', 'color' => '#3B82F6', 'rank' => 2],
        'qualified' => ['label' => 'Qualified', 'color' => '#2563EB', 'rank' => 3],
        'opportunity' => ['label' => 'Opportunity', 'color' => '#4F46E5', 'rank' => 4],
        'proposal' => ['label' => 'Proposal', 'color' => '#4338CA', 'rank' => 5],
        'won' => ['label' => 'Won', 'color' => '#16A34A', 'rank' => 100],
        'lost' => ['label' => 'Lost', 'color' => '#E5484D', 'rank' => -1],
    ];

    public static function all(): array
    {
        return array_merge(self::STAGES, self::OUTCOMES);
    }

    public static function label(string $stage): string
    {
        return self::META[$stage]['label'] ?? ucfirst($stage);
    }

    public static function isOutcome(string $stage): bool
    {
        return in_array($stage, self::OUTCOMES, true);
    }

    /** Catalog for the frontend (stages + outcomes with color/label/rank). */
    public static function catalog(): array
    {
        return collect(self::META)
            ->map(fn ($m, $key) => [
                'key' => $key,
                'label' => $m['label'],
                'color' => $m['color'],
                'rank' => $m['rank'],
                'is_outcome' => self::isOutcome($key),
            ])
            ->values()
            ->all();
    }
}
