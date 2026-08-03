<?php

namespace App\Exports;

use App\Models\Contact;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ContactsExport implements FromCollection, WithHeadings
{
    public function __construct(private ?int $ownerId = null, private ?string $source = null, private ?string $search = null) {}

    public function collection()
    {
        return Contact::query()
            ->when($this->ownerId, fn ($q) => $q->where('owner_id', $this->ownerId))
            ->when($this->source, fn ($q) => $q->where('source', $this->source))
            ->when($this->search, fn ($q) => $q->where(fn ($w) => $w
                ->where('business_name', 'like', "%{$this->search}%")
                ->orWhere('contact_person', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")
                ->orWhere('phone', 'like', "%{$this->search}%")))
            ->orderBy('business_name')
            ->get()
            ->map(fn (Contact $c) => [
                $c->business_name,
                $c->contact_person,
                $c->phone,
                $c->email,
                $c->industry,
                $c->city,
                $c->address,
                $c->source,
                $c->notes,
                optional($c->created_at)->toDateString(),
            ]);
    }

    public function headings(): array
    {
        return ['Business', 'Contact Person', 'Phone', 'Email', 'Industry', 'City', 'Address', 'Source', 'Notes', 'Added'];
    }
}
