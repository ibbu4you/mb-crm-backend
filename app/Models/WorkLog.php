<?php

namespace App\Models;

use App\Support\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkLog extends Model
{
    use Auditable;

    protected $fillable = [
        'user_id', 'attendance_id', 'slot_at', 'log_date', 'mode',
        'note', 'link_type', 'link_id', 'link_label', 'is_late',
    ];

    protected function casts(): array
    {
        return [
            'slot_at' => 'datetime',
            'log_date' => 'date',
            'is_late' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }
}
