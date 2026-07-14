<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadType;
use App\Models\User;
use App\Support\Pipeline;
use Illuminate\Database\Seeder;

class CrmSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Free Spotlight', 'color' => '#60A5FA', 'sort_order' => 1],
            ['name' => 'Go Viral', 'color' => '#4F46E5', 'sort_order' => 2],
            ['name' => 'Branding Consultation', 'color' => '#16A34A', 'sort_order' => 3],
            ['name' => 'Automation', 'color' => '#D97706', 'sort_order' => 4],
            ['name' => 'Package Enquiry', 'color' => '#E5484D', 'sort_order' => 5],
        ];
        foreach ($types as $t) {
            LeadType::updateOrCreate(['name' => $t['name']], $t);
        }
        $typeIds = LeadType::pluck('id')->all();

        $owner = User::where('email', 'sales@malayznbeat.com')->first()?->id
            ?? User::where('email', 'admin@malayznbeat.com')->value('id');

        $businesses = [
            ['Nasi Kandar Pelita', 'Ahmad Faisal', 'ahmad@pelita.my', '+60123456789', 'F&B', 'Kuala Lumpur'],
            ['Sunway Property', 'Lim Wei Ling', 'wl@sunway.my', '+60127654321', 'Real Estate', 'Petaling Jaya'],
            ['Zara Boutique KL', 'Nurul Aina', 'aina@zarakl.my', '+60198887766', 'Retail', 'Kuala Lumpur'],
            ['TechNova Solutions', 'Ravi Kumar', 'ravi@technova.my', '+60165554433', 'Technology', 'Cyberjaya'],
            ['Kopitiam Heritage', 'Tan Ah Kow', 'akow@kopitiam.my', '+60134445566', 'F&B', 'Ipoh'],
            ['Glow Beauty Spa', 'Siti Sarah', 'sarah@glowspa.my', '+60112223344', 'Wellness', 'Johor Bahru'],
            ['AutoCare Workshop', 'Kumar Selva', 'kumar@autocare.my', '+60176667788', 'Automotive', 'Klang'],
            ['Little Steps Nursery', 'Farah Diana', 'farah@littlesteps.my', '+60128889900', 'Education', 'Shah Alam'],
            ['Green Valley Organics', 'Wong Mei', 'mei@greenvalley.my', '+60195556677', 'F&B', 'Penang'],
            ['Elite Fitness Hub', 'Daniel Lim', 'daniel@elitefit.my', '+60123334455', 'Fitness', 'Kuala Lumpur'],
            ['Batik Warisan', 'Zulkifli Hassan', 'zul@batikwarisan.my', '+60147778899', 'Retail', 'Melaka'],
            ['CloudPoint Cafe', 'Jasmine Teoh', 'jasmine@cloudpoint.my', '+60181112233', 'F&B', 'Subang Jaya'],
        ];

        $stages = Pipeline::STAGES;
        $i = 0;
        foreach ($businesses as [$name, $person, $email, $phone, $industry, $city]) {
            $contact = Contact::updateOrCreate(
                ['email' => $email],
                [
                    'business_name' => $name,
                    'contact_person' => $person,
                    'phone' => $phone,
                    'industry' => $industry,
                    'city' => $city,
                    'source' => ['whatsapp', 'web', 'field', 'manual', 'referral'][$i % 5],
                    'owner_id' => $owner,
                    'created_by' => $owner,
                ],
            );

            // Give most contacts one lead; vary stage/outcome.
            $stage = $i < 8 ? $stages[$i % count($stages)] : ($i % 2 ? 'won' : 'lost');
            $status = match ($stage) { 'won' => 'won', 'lost' => 'lost', default => 'active' };

            Lead::updateOrCreate(
                ['contact_id' => $contact->id],
                [
                    'lead_type_id' => $typeIds[$i % count($typeIds)],
                    'title' => $name.' — '.LeadType::find($typeIds[$i % count($typeIds)])->name,
                    'pipeline_stage' => $stage,
                    'status' => $status,
                    'source' => $contact->source,
                    'owner_id' => $owner,
                    'revenue_potential' => [1500, 2000, 5000, 10000, 3500, 8000][$i % 6],
                    'last_activity_at' => now()->subDays($i),
                ],
            );
            $i++;
        }
    }
}
