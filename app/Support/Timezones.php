<?php

namespace App\Support;

/**
 * Curated list of employee timezones. Single source of truth for the allow-list
 * used to validate the users.timezone column; the frontend mirrors this in
 * web/src/lib/timezones.ts. Keyed by IANA zone → country label.
 */
class Timezones
{
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
