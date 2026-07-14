<?php

namespace App\Imports;

use App\Models\Contact;
use App\Models\Lead;
use App\Support\Pipeline;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Import leads from a spreadsheet (Business, Contact Person, Phone, Email,
 * Industry, City, Revenue Potential). Contacts are de-duplicated by phone/email;
 * a new lead is created for the contact when it has none yet. Source = manual.
 */
class LeadsImport implements ToCollection, WithHeadingRow
{
    public int $imported = 0;
    public int $skipped = 0;

    public function __construct(private int $userId) {}

    public function collection(Collection $rows): void
    {
        foreach ($rows as $row) {
            $business = $row['business'] ?? $row['business_name'] ?? null;
            if (! $business) {
                $this->skipped++;
                continue;
            }
            $phone = $row['phone'] ?? null;
            $email = $row['email'] ?? null;
            $normalized = $phone ? preg_replace('/\D+/', '', (string) $phone) : null;

            $contact = Contact::query()
                ->when($normalized, fn ($q) => $q->orWhere('phone_normalized', $normalized))
                ->when($email, fn ($q) => $q->orWhere('email', $email))
                ->first();

            if (! $contact) {
                $contact = Contact::create([
                    'business_name' => $business,
                    'contact_person' => $row['contact_person'] ?? $row['contact'] ?? null,
                    'phone' => $phone ? (string) $phone : null,
                    'email' => $email,
                    'industry' => $row['industry'] ?? null,
                    'city' => $row['city'] ?? null,
                    'source' => 'manual',
                    'owner_id' => $this->userId,
                    'created_by' => $this->userId,
                ]);
            }

            $stage = strtolower(trim((string) ($row['stage'] ?? 'intake')));
            if (! in_array($stage, Pipeline::all(), true)) {
                $stage = 'intake';
            }

            if (! Lead::where('contact_id', $contact->id)->exists()) {
                Lead::create([
                    'contact_id' => $contact->id,
                    'pipeline_stage' => $stage,
                    'status' => 'active',
                    'source' => 'manual',
                    'owner_id' => $this->userId,
                    'revenue_potential' => (float) ($row['revenue_potential'] ?? 0),
                    'last_activity_at' => now(),
                ]);
                $this->imported++;
            } else {
                $this->skipped++;
            }
        }
    }
}
