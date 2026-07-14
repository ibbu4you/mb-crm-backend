<?php

namespace App\Http\Resources;

use App\Support\ViralWorkflow as VW;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ViralDeliverableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'kind_label' => VW::kindLabel($this->kind),
            'slot_number' => $this->slot_number,
            'title' => $this->title,
            'stage' => $this->stage,
            'stage_label' => VW::stageLabel($this->stage),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee?->only('id', 'name')),
            'caption' => $this->caption,
            'hashtags' => $this->hashtags,
            'target_audience' => $this->target_audience,
            'landing_page_url' => $this->landing_page_url,
            'file_url' => $this->file_url,
            'filename' => $this->filename,
            'submitted_at' => $this->submitted_at,
            'approved_at' => $this->approved_at,
            'history' => $this->whenLoaded('history', fn () => $this->history->map(fn ($h) => [
                'id' => $h->id, 'to_stage' => $h->to_stage, 'to_label' => VW::stageLabel($h->to_stage),
                'notes' => $h->notes, 'changed_by' => $h->changer?->only('id', 'name'), 'changed_at' => $h->changed_at,
            ])),
        ];
    }
}
