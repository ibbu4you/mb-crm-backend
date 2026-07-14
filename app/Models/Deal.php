<?php

namespace App\Models;

use App\Support\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deal extends Model
{
    use Auditable;

    protected $fillable = ['lead_id', 'user_id', 'outcome', 'actual_revenue', 'notes', 'closed_at'];

    protected function casts(): array
    {
        return ['actual_revenue' => 'decimal:2', 'closed_at' => 'date'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
