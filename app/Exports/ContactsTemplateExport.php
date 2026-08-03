<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ContactsTemplateExport implements FromArray, WithHeadings
{
    public function array(): array
    {
        // One example row to guide the user.
        return [['Warung Pak Din', 'Pak Din', '60123456789', 'pakdin@example.my', 'F&B', 'Kuala Lumpur', 'Jln Ampang, KL', 'Met at expo']];
    }

    public function headings(): array
    {
        return ['Business', 'Contact Person', 'Phone', 'Email', 'Industry', 'City', 'Address', 'Notes'];
    }
}
