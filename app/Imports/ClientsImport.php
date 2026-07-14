<?php

namespace App\Imports;

use App\Models\Contact;
use App\Models\Lead;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ClientsImport implements ToCollection, WithHeadingRow
{
    public int $imported = 0;

    public function __construct(private int $userId) {}

    public function collection(Collection $rows): void
    {
        foreach ($rows as $row) {
            $business = $row['business'] ?? $row['business_name'] ?? null;
            if (! $business) {
                continue;
            }
            $phone = $row['phone'] ?? null;
            $email = $row['email'] ?? null;
            $normalized = $phone ? preg_replace('/\D+/', '', $phone) : null;

            $contact = Contact::query()
                ->when($normalized, fn ($q) => $q->orWhere('phone_normalized', $normalized))
                ->when($email, fn ($q) => $q->orWhere('email', $email))
                ->first();

            if (! $contact) {
                $contact = Contact::create([
                    'business_name' => $business,
                    'contact_person' => $row['contact_person'] ?? $row['contact'] ?? null,
                    'phone' => $phone,
                    'email' => $email,
                    'industry' => $row['industry'] ?? null,
                    'city' => $row['city'] ?? null,
                    'source' => 'field',
                    'owner_id' => $this->userId,
                    'created_by' => $this->userId,
                ]);
            }

            // Create a lead only if this contact doesn't already have one.
            if (! Lead::where('contact_id', $contact->id)->exists()) {
                Lead::create([
                    'contact_id' => $contact->id,
                    'pipeline_stage' => 'intake',
                    'status' => 'active',
                    'source' => 'field',
                    'owner_id' => $this->userId,
                    'revenue_potential' => (float) ($row['revenue_potential'] ?? 0),
                    'last_activity_at' => now(),
                ]);
            }
            $this->imported++;
        }
    }
}
