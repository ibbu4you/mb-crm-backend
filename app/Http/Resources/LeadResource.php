<?php

namespace App\Http\Resources;

use App\Support\LeadDetails;
use App\Support\Pipeline;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'contact_id' => $this->contact_id,
            'contact' => $this->whenLoaded('contact', fn () => new ContactResource($this->contact)),
            'lead_type_id' => $this->lead_type_id,
            'type' => $this->whenLoaded('type', fn () => $this->type?->only('id', 'name', 'color')),
            'pipeline_stage' => $this->pipeline_stage,
            'stage_label' => Pipeline::label($this->pipeline_stage),
            'status' => $this->status,
            'source' => $this->source,
            'owner_id' => $this->owner_id,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner?->only('id', 'name')),
            'revenue_potential' => (float) $this->revenue_potential,
            'expected_close_date' => $this->expected_close_date?->toDateString(),
            'last_activity_at' => $this->last_activity_at,
            'notes' => $this->notes,
            'notes_thread' => $this->whenLoaded('comments', fn () => LeadNoteResource::collection($this->comments)),
            // Additional details (WhatsApp intake form) — only on the detail view.
            'details' => $this->when($this->relationLoaded('comments'), fn () => LeadDetails::fromMeta($this->meta)),
            'created_at' => $this->created_at,
        ];
    }
}
