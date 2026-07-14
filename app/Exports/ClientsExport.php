<?php

namespace App\Exports;

use App\Models\Lead;
use App\Support\Pipeline;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ClientsExport implements FromCollection, WithHeadings
{
    public function __construct(private ?int $ownerId = null) {}

    public function collection()
    {
        return Lead::with('contact')
            ->when($this->ownerId, fn ($q) => $q->where('owner_id', $this->ownerId))
            ->get()
            ->map(fn (Lead $l) => [
                $l->contact?->business_name,
                $l->contact?->contact_person,
                $l->contact?->phone,
                $l->contact?->email,
                $l->contact?->industry,
                $l->contact?->city,
                Pipeline::label($l->pipeline_stage),
                $l->status,
                (float) $l->revenue_potential,
            ]);
    }

    public function headings(): array
    {
        return ['Business', 'Contact Person', 'Phone', 'Email', 'Industry', 'City', 'Stage', 'Status', 'Revenue Potential'];
    }
}
