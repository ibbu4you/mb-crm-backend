<?php

namespace App\Http\Resources;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'status_label' => Task::STATUS_META[$this->status]['label'] ?? $this->status,
            'priority' => $this->priority,
            'priority_label' => Task::PRIORITY_META[$this->priority]['label'] ?? $this->priority,
            'due_date' => $this->due_date?->toDateString(),
            'is_overdue' => $this->due_date && $this->due_date->isPast() && $this->status !== 'done',
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee?->only('id', 'name')),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->only('id', 'name')),
            'link_type' => $this->taskable_type ? Str::of($this->taskable_type)->afterLast('\\')->lower()->value() : null,
            'link_id' => $this->taskable_id,
            'link_label' => $this->taskable_label,
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
        ];
    }
}
