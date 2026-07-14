<?php

namespace App\Http\Resources;

use App\Support\SupportDesk;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'subject' => $this->subject,
            'description' => $this->description,
            'priority' => $this->priority,
            'priority_label' => SupportDesk::priorityLabel($this->priority),
            'status' => $this->status,
            'status_label' => SupportDesk::statusLabel($this->status),
            'reporter' => $this->whenLoaded('reporter', fn () => $this->reporter?->only('id', 'name')),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee?->only('id', 'name')),
            'last_activity_at' => $this->last_activity_at,
            'resolved_at' => $this->resolved_at,
            'closed_at' => $this->closed_at,
            'replies_count' => $this->whenCounted('replies'),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($a) => ['id' => $a->id, 'name' => $a->original_name, 'url' => $a->url])),
            'replies' => $this->whenLoaded('replies', fn () => $this->replies->map(fn ($r) => [
                'id' => $r->id,
                'body' => $r->body,
                'is_system' => $r->is_system,
                'user' => $r->user?->only('id', 'name'),
                'attachments' => $r->relationLoaded('attachments') ? $r->attachments->map(fn ($a) => ['id' => $a->id, 'name' => $a->original_name, 'url' => $a->url]) : [],
                'created_at' => $r->created_at,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
