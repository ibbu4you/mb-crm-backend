<?php

namespace App\Models;

use App\Support\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Visit extends Model
{
    use Auditable;

    protected $fillable = [
        'lead_id', 'user_id', 'visit_date', 'visit_level', 'person_met', 'contact_phone',
        'decision_maker_met', 'interested', 'follow_up_done', 'revenue_potential', 'notes', 'photo_path',
        'lat', 'lng', 'accuracy', 'address',
    ];

    protected function casts(): array
    {
        return [
            'visit_date' => 'date',
            'decision_maker_met' => 'boolean',
            'interested' => 'boolean',
            'follow_up_done' => 'boolean',
            'revenue_potential' => 'decimal:2',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? asset('storage/'.$this->photo_path) : null;
    }
}
