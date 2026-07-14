<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ViralHistory extends Model
{
    protected $table = 'viral_package_history';

    protected $fillable = ['deliverable_id', 'from_stage', 'to_stage', 'changed_by', 'notes', 'changed_at'];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
