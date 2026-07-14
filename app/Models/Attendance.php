<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    protected $fillable = [
        'user_id', 'date', 'check_in_at', 'check_out_at',
        'in_lat', 'in_lng', 'in_accuracy', 'in_address', 'in_photo_path',
        'out_lat', 'out_lng', 'out_address', 'out_photo_path',
        'status', 'on_site', 'office_location_id', 'work_minutes', 'note',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date', 'check_in_at' => 'datetime', 'check_out_at' => 'datetime',
            'in_lat' => 'float', 'in_lng' => 'float', 'out_lat' => 'float', 'out_lng' => 'float',
            'on_site' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(OfficeLocation::class, 'office_location_id');
    }

    public function getInPhotoUrlAttribute(): ?string
    {
        return $this->in_photo_path ? asset('storage/'.$this->in_photo_path) : null;
    }

    public function getOutPhotoUrlAttribute(): ?string
    {
        return $this->out_photo_path ? asset('storage/'.$this->out_photo_path) : null;
    }

    /** Haversine distance in metres between two points. */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
