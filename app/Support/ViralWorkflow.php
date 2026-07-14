<?php

namespace App\Support;

/**
 * Viral package deliverable workflow — ported from Content Hub.
 * Deliverable stages: pending -> in_progress -> review -> approved.
 * Kinds: article, social_post, reel, landing_page.
 */
class ViralWorkflow
{
    public const PENDING = 'pending';
    public const IN_PROGRESS = 'in_progress';
    public const REVIEW = 'review';
    public const APPROVED = 'approved';

    public const STAGES = [
        'pending' => ['label' => 'Pending', 'color' => '#64748B'],
        'in_progress' => ['label' => 'In progress', 'color' => '#6366F1'],
        'review' => ['label' => 'Review', 'color' => '#D97706'],
        'approved' => ['label' => 'Approved', 'color' => '#16A34A'],
    ];

    public const KINDS = [
        'article' => 'Article',
        'social_post' => 'Social Post',
        'reel' => 'Reel',
        'landing_page' => 'Landing Page',
    ];

    // Auto-seeded deliverable counts (Content Hub defaults).
    public const DEFAULT_POST_COUNT = 8;
    public const DEFAULT_REEL_COUNT = 2;

    public static function stageLabel(string $stage): string
    {
        return self::STAGES[$stage]['label'] ?? ucfirst($stage);
    }

    public static function kindLabel(string $kind): string
    {
        return self::KINDS[$kind] ?? ucfirst($kind);
    }

    public static function catalog(): array
    {
        return [
            'stages' => collect(self::STAGES)->map(fn ($m, $k) => ['key' => $k, 'label' => $m['label'], 'color' => $m['color']])->values()->all(),
            'kinds' => collect(self::KINDS)->map(fn ($label, $k) => ['key' => $k, 'label' => $label])->values()->all(),
        ];
    }
}
