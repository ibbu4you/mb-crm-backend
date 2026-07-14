<?php

namespace App\Http\Resources;

use App\Support\ViralWorkflow as VW;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ViralPackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $deliverables = $this->whenLoaded('deliverables');
        $isColl = $deliverables instanceof \Illuminate\Support\Collection;
        $total = $isColl ? $deliverables->count() : null;
        $approved = $isColl ? $deliverables->where('stage', VW::APPROVED)->count() : null;
        $assets = $isColl ? $deliverables->filter(fn ($d) => $d->filename || $d->file_url)->count() : null;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'status' => $this->status,
            'completed_at' => $this->completed_at,
            'contact' => $this->whenLoaded('contact', fn () => $this->contact?->only('id', 'business_name', 'contact_person')),
            'sales_rep' => $this->whenLoaded('salesRep', fn () => $this->salesRep?->only('id', 'name')),
            'tech_team' => $this->whenLoaded('techTeam', fn () => $this->techTeam?->only('id', 'name')),
            'progress' => $total !== null ? ['approved' => $approved, 'total' => $total] : null,
            'assets_count' => $assets,
            'deliverables' => $this->whenLoaded('deliverables', fn () => ViralDeliverableResource::collection($this->deliverables)),
            'created_at' => $this->created_at,
        ];
    }
}
