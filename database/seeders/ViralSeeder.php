<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\User;
use App\Models\ViralPackage;
use App\Services\ViralPackageService;
use App\Support\ViralWorkflow as VW;
use Illuminate\Database\Seeder;

class ViralSeeder extends Seeder
{
    public function run(ViralPackageService $service): void
    {
        $sales = User::where('email', 'sales@malayznbeat.com')->first()
            ?? User::where('email', 'admin@malayznbeat.com')->first();
        $writer = User::where('email', 'writer@malayznbeat.com')->value('id');
        $contacts = Contact::limit(3)->get();

        foreach ($contacts as $i => $contact) {
            if (ViralPackage::where('contact_id', $contact->id)->where('status', 'active')->exists()) {
                continue;
            }
            $package = $service->create($contact->id, $sales, [
                'title' => $contact->business_name.' — Go Viral',
                'with_landing' => $i === 0,
            ]);

            // Advance some deliverables for variety on the first package.
            if ($i === 0) {
                $ds = $package->deliverables()->get();
                foreach ($ds->take(4) as $k => $d) {
                    $d->update(['stage' => [VW::IN_PROGRESS, VW::REVIEW, VW::APPROVED, VW::APPROVED][$k], 'assigned_to' => $writer]);
                    $d->history()->create(['from_stage' => VW::PENDING, 'to_stage' => $d->stage, 'changed_by' => $writer, 'changed_at' => now()->subDays(3 - $k)]);
                }
            }
        }
    }
}
