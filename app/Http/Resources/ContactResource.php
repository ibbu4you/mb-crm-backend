<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_name' => $this->business_name,
            'contact_person' => $this->contact_person,
            'email' => $this->email,
            'phone' => $this->phone,
            'industry' => $this->industry,
            'city' => $this->city,
            'address' => $this->address,
            'source' => $this->source,
            'owner_id' => $this->owner_id,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner?->only('id', 'name')),
            'notes' => $this->notes,
            'leads_count' => $this->whenCounted('leads'),
            'articles_count' => $this->whenCounted('articles'),
            'viral_packages_count' => $this->whenCounted('viralPackages'),
            'leads' => $this->whenLoaded('leads', fn () => LeadResource::collection($this->leads)),
            'created_at' => $this->created_at,
        ];
    }
}
