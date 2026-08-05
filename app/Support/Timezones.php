<?php

namespace App\Support;

/**
 * Curated list of employee timezones. Single source of truth for the allow-list
 * used to validate the users.timezone column; the frontend mirrors this in
 * web/src/lib/timezones.ts. Keyed by IANA zone → country label.
 */
class Timezones
{
    /**
     * The company's base timezone. Used as the fallback for employees without an
     * explicit timezone AND as the anchor for all attendance / work-status wall-clock
     * math — deliberately NOT config('app.timezone'), because the live server runs in
     * UTC. Anchoring here keeps Malaysian employees on KL regardless of the server tz.
     */
    public const BUSINESS = 'Asia/Kuala_Lumpur';

    public static function business(): string
    {
        return self::BUSINESS;
    }

    public const ZONES = [
        'Asia/Kuala_Lumpur' => 'Malaysia',
        'Asia/Kolkata' => 'India',
        'Asia/Singapore' => 'Singapore',
        'Asia/Jakarta' => 'Indonesia (WIB)',
        'Asia/Manila' => 'Philippines',
        'Asia/Dubai' => 'UAE',
        'Europe/London' => 'United Kingdom',
        'America/New_York' => 'US (Eastern)',
    ];

    /** @return array<int, string> valid IANA zone identifiers */
    public static function keys(): array
    {
        return array_keys(self::ZONES);
    }

    /** @return array<int, array{value: string, label: string}> for pickers */
    public static function options(): array
    {
        return collect(self::ZONES)->map(fn ($label, $zone) => ['value' => $zone, 'label' => $label])->values()->all();
    }
}
