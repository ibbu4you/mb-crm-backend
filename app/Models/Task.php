<?php

namespace App\Models;

use App\Support\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Task extends Model
{
    use Auditable;

    public const STATUSES = ['todo', 'in_progress', 'blocked', 'done'];

    public const STATUS_META = [
        'todo' => ['label' => 'To do', 'color' => '#64748B'],
        'in_progress' => ['label' => 'In progress', 'color' => '#1d4ed8'],
        'blocked' => ['label' => 'Blocked', 'color' => '#E5484D'],
        'done' => ['label' => 'Done', 'color' => '#16A34A'],
    ];

    public const PRIORITY_META = [
        'low' => ['label' => 'Low', 'color' => '#64748B'],
        'medium' => ['label' => 'Medium', 'color' => '#0EA5E9'],
        'high' => ['label' => 'High', 'color' => '#D97706'],
        'urgent' => ['label' => 'Urgent', 'color' => '#E5484D'],
    ];

    protected $fillable = [
        'title', 'description', 'assignee_id', 'created_by', 'due_date', 'priority',
        'status', 'taskable_type', 'taskable_id', 'taskable_label', 'completed_at', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'completed_at' => 'datetime'];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function taskable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_META[$this->status]['label'] ?? ucfirst($this->status);
    }

    public function getPriorityLabelAttribute(): string
    {
        return self::PRIORITY_META[$this->priority]['label'] ?? ucfirst($this->priority);
    }

    public static function catalog(): array
    {
        return [
            'statuses' => collect(self::STATUS_META)->map(fn ($m, $k) => ['key' => $k, 'label' => $m['label'], 'color' => $m['color']])->values()->all(),
            'priorities' => collect(self::PRIORITY_META)->map(fn ($m, $k) => ['key' => $k, 'label' => $m['label'], 'color' => $m['color']])->values()->all(),
        ];
    }
}
