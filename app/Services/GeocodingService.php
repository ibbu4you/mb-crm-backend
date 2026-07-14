<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Reverse geocoding via OpenStreetMap Nominatim (free, no key).
 * Cached by rounded coordinates to respect the ~1 req/sec usage policy.
 */
class GeocodingService
{
    public function reverse(float $lat, float $lng): ?string
    {
        $key = 'geocode.'.round($lat, 4).','.round($lng, 4);

        return Cache::remember($key, now()->addDays(30), function () use ($lat, $lng) {
            try {
                $res = Http::withHeaders(['User-Agent' => 'MalayznbeatCRM/1.0 (attendance)'])
                    ->timeout(8)
                    ->get('https://nominatim.openstreetmap.org/reverse', [
                        'format' => 'jsonv2', 'lat' => $lat, 'lon' => $lng, 'zoom' => 18, 'addressdetails' => 1,
                    ]);

                return $res->successful() ? ($res->json('display_name') ?: null) : null;
            } catch (\Throwable $e) {
                return null;
            }
        });
    }
}
