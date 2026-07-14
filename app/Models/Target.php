<?php

namespace App\Models;

use App\Support\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Target extends Model
{
    use Auditable;

    protected $fillable = ['user_id', 'period', 'visits_target', 'revenue_target'];

    protected function casts(): array
    {
        return ['revenue_target' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function currentPeriod(): string
    {
        return now()->format('Y-m');
    }
}
