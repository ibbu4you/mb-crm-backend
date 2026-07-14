<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ViralDeliverable extends Model
{
    protected $table = 'viral_package_deliverables';

    protected $fillable = [
        'viral_package_id', 'kind', 'slot_number', 'title', 'stage', 'assigned_to',
        'file_path', 'filename', 'mime_type', 'file_size', 'caption', 'hashtags',
        'target_audience', 'landing_page_url', 'submitted_at', 'approved_at',
    ];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(ViralPackage::class, 'viral_package_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function history(): HasMany
    {
        return $this->hasMany(ViralHistory::class, 'deliverable_id')->latest('changed_at');
    }

    public function getFileUrlAttribute(): ?string
    {
        return $this->file_path ? asset('storage/'.$this->file_path) : null;
    }
}
