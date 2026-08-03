<?php

namespace App\Imports;

use App\Models\Contact;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ContactsImport implements ToCollection, WithHeadingRow
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
            $phone = isset($row['phone']) ? (string) $row['phone'] : null;
            $email = $row['email'] ?? null;
            $normalized = $phone ? preg_replace('/\D+/', '', $phone) : null;

            // Skip rows that already match an existing contact by phone or email.
            $exists = Contact::query()
                ->when($normalized, fn ($q) => $q->orWhere('phone_normalized', $normalized))
                ->when($email, fn ($q) => $q->orWhere('email', $email))
                ->exists();
            if ($exists) {
                continue;
            }

            Contact::create([
                'business_name' => $business,
                'contact_person' => $row['contact_person'] ?? $row['contact'] ?? null,
                'phone' => $phone,
                'email' => $email,
                'industry' => $row['industry'] ?? null,
                'city' => $row['city'] ?? null,
                'address' => $row['address'] ?? null,
                'notes' => $row['notes'] ?? null,
                'source' => 'manual',
                'owner_id' => $this->userId,
                'created_by' => $this->userId,
            ]);
            $this->imported++;
        }
    }
}
