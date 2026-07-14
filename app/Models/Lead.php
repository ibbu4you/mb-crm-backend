<?php

namespace App\Models;

use App\Support\Pipeline;
use App\Support\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use Auditable, SoftDeletes;

    public const DORMANT_DAYS = 30;

    protected $fillable = [
        'contact_id', 'lead_type_id', 'title', 'pipeline_stage', 'status', 'source',
        'owner_id', 'revenue_potential', 'expected_close_date', 'last_activity_at', 'notes', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'revenue_potential' => 'decimal:2',
            'expected_close_date' => 'date',
            'last_activity_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeadType::class, 'lead_type_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    // Named `comments` (not `notes`) to avoid colliding with the `notes` text column.
    public function comments(): HasMany
    {
        return $this->hasMany(LeadNote::class)->latest();
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class)->latest('visit_date');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function getStageLabelAttribute(): string
    {
        return Pipeline::label($this->pipeline_stage);
    }

    /**
     * Recompute the derived pipeline fields from the visit history — highest
     * stage reached, last activity, and dormant flag. Ported from MB Sales.
     * Won/Lost leads are terminal and left untouched (deals govern them).
     */
    public function refreshPipeline(): void
    {
        if (in_array($this->status, ['won', 'lost'], true)) {
            return;
        }

        $visits = $this->visits()->get();
        if ($visits->isEmpty()) {
            return;
        }

        $lastVisit = $visits->max('visit_date');
        $bestRank = $visits->map(fn ($v) => Pipeline::META[$v->visit_level]['rank'] ?? 0)->max();
        $stage = collect(Pipeline::STAGES)->first(fn ($s) => (Pipeline::META[$s]['rank'] ?? 0) === $bestRank);

        $this->pipeline_stage = $stage ?? $this->pipeline_stage;
        $this->last_activity_at = $lastVisit;
        $this->status = now()->diffInDays($lastVisit) > self::DORMANT_DAYS ? 'dormant' : 'active';
        $this->saveQuietly();
    }
}
