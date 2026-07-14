<?php

namespace App\Support;

/**
 * Support desk status machine + priorities — ported from Content Hub.
 */
class SupportDesk
{
    public const STATUSES = [
        'open' => ['label' => 'Open', 'color' => '#0EA5E9'],
        'in_progress' => ['label' => 'In progress', 'color' => '#6366F1'],
        'waiting_user' => ['label' => 'Waiting on user', 'color' => '#D97706'],
        'resolved' => ['label' => 'Resolved', 'color' => '#16A34A'],
        'closed' => ['label' => 'Closed', 'color' => '#64748B'],
    ];

    public const PRIORITIES = [
        'low' => ['label' => 'Low', 'color' => '#64748B'],
        'normal' => ['label' => 'Normal', 'color' => '#0EA5E9'],
        'high' => ['label' => 'High', 'color' => '#D97706'],
        'urgent' => ['label' => 'Urgent', 'color' => '#E5484D'],
    ];

    public static function statusLabel(string $s): string
    {
        return self::STATUSES[$s]['label'] ?? ucfirst($s);
    }

    public static function priorityLabel(string $p): string
    {
        return self::PRIORITIES[$p]['label'] ?? ucfirst($p);
    }

    public static function catalog(): array
    {
        return [
            'statuses' => collect(self::STATUSES)->map(fn ($m, $k) => ['key' => $k, 'label' => $m['label'], 'color' => $m['color']])->values()->all(),
            'priorities' => collect(self::PRIORITIES)->map(fn ($m, $k) => ['key' => $k, 'label' => $m['label'], 'color' => $m['color']])->values()->all(),
        ];
    }
}
