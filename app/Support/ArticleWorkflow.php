<?php

namespace App\Support;

/**
 * The article production workflow — ported from Content Hub. The enum keeps all
 * 8 stages for fidelity, but the live flow skips `internal_review` (writers
 * submit straight to `client_approval` / "Sales review"). Labels intentionally
 * match Content Hub (they don't mirror the keys).
 */
class ArticleWorkflow
{
    public const INBOX = 'inbox';
    public const ASSIGNED = 'assigned';
    public const IN_PROGRESS = 'in_progress';
    public const INTERNAL_REVIEW = 'internal_review'; // legacy, skipped
    public const CLIENT_APPROVAL = 'client_approval';
    public const REVISIONS = 'revisions';
    public const APPROVED = 'approved';
    public const PUBLISHED = 'published';

    public const META = [
        'inbox' => ['label' => 'New submission', 'color' => '#64748B'],
        'assigned' => ['label' => 'Assigned', 'color' => '#0EA5E9'],
        'in_progress' => ['label' => 'Writing', 'color' => '#6366F1'],
        'internal_review' => ['label' => 'Internal review', 'color' => '#8B5CF6'],
        'client_approval' => ['label' => 'Sales review', 'color' => '#D97706'],
        'revisions' => ['label' => 'Correction', 'color' => '#E5484D'],
        'approved' => ['label' => 'Verified', 'color' => '#16A34A'],
        'published' => ['label' => 'Published', 'color' => '#0F766E'],
    ];

    /** Stages shown on the board, in order (internal_review hidden). */
    public const BOARD = ['inbox', 'assigned', 'in_progress', 'client_approval', 'revisions', 'approved', 'published'];

    public static function all(): array
    {
        return array_keys(self::META);
    }

    public static function label(string $stage): string
    {
        return self::META[$stage]['label'] ?? ucfirst($stage);
    }

    public static function isTerminal(string $stage): bool
    {
        return $stage === self::PUBLISHED;
    }

    public static function catalog(): array
    {
        return collect(self::BOARD)->map(fn ($s) => [
            'key' => $s,
            'label' => self::META[$s]['label'],
            'color' => self::META[$s]['color'],
        ])->all();
    }
}
