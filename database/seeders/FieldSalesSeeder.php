<?php

namespace Database\Seeders;

use App\Models\Deal;
use App\Models\Lead;
use App\Models\Target;
use App\Models\User;
use App\Models\Visit;
use App\Support\Pipeline;
use Illuminate\Database\Seeder;

class FieldSalesSeeder extends Seeder
{
    public function run(): void
    {
        $leads = Lead::with('contact')->get();
        $stages = Pipeline::STAGES;

        foreach ($leads as $index => $lead) {
            $owner = $lead->owner_id ?? User::where('email', 'admin@malayznbeat.com')->value('id');

            if (in_array($lead->status, ['won', 'lost'], true)) {
                Deal::create([
                    'lead_id' => $lead->id,
                    'user_id' => $owner,
                    'outcome' => $lead->status,
                    'actual_revenue' => $lead->status === 'won' ? $lead->revenue_potential : null,
                    'notes' => null,
                    'closed_at' => now()->subDays(rand(1, 40)),
                ]);

                // A couple of historical visits leading up to the close.
                foreach (range(1, rand(1, 3)) as $n) {
                    Visit::create([
                        'lead_id' => $lead->id,
                        'user_id' => $owner,
                        'visit_date' => now()->subDays(rand(10, 60)),
                        'visit_level' => $stages[array_rand($stages)],
                        'person_met' => $lead->contact->contact_person,
                        'contact_phone' => $lead->contact->phone,
                        'decision_maker_met' => (bool) rand(0, 1),
                        'interested' => (bool) rand(0, 1),
                        'follow_up_done' => (bool) rand(0, 1),
                        'revenue_potential' => $lead->revenue_potential,
                    ]);
                }

                continue;
            }

            // Open leads: 1-4 visits, most recent within the last few weeks.
            $count = rand(1, 4);
            for ($i = 0; $i < $count; $i++) {
                $daysAgo = $i === 0 ? rand(0, 20) : rand(20, 70);
                Visit::create([
                    'lead_id' => $lead->id,
                    'user_id' => $owner,
                    'visit_date' => now()->subDays($daysAgo),
                    'visit_level' => $stages[min($i, count($stages) - 1)],
                    'person_met' => $lead->contact->contact_person,
                    'contact_phone' => $lead->contact->phone,
                    'decision_maker_met' => (bool) rand(0, 1),
                    'interested' => (bool) rand(0, 1),
                    'follow_up_done' => (bool) rand(0, 1),
                    'revenue_potential' => $lead->revenue_potential,
                    'notes' => 'Site visit — discussed requirements.',
                ]);
            }

            // Follow-ups spread across buckets (overdue / today / upcoming).
            if ($index % 3 === 0) {
                $lead->followUps()->create([
                    'user_id' => $owner,
                    'due_date' => now()->subDays(rand(1, 5)),
                    'note' => 'Send revised quote',
                    'status' => 'pending',
                ]);
            } elseif ($index % 3 === 1) {
                $lead->followUps()->create([
                    'user_id' => $owner,
                    'due_date' => now(),
                    'note' => 'Call to confirm meeting',
                    'status' => 'pending',
                ]);
            } else {
                $lead->followUps()->create([
                    'user_id' => $owner,
                    'due_date' => now()->addDays(rand(1, 7)),
                    'note' => 'Follow up on proposal',
                    'status' => 'pending',
                ]);
            }

            $lead->refreshPipeline();
        }

        // Monthly targets for salespeople (this month + last month).
        $salesUsers = User::whereIn('email', ['sales@malayznbeat.com', 'admin@malayznbeat.com'])->get();
        foreach ($salesUsers as $u) {
            foreach ([now()->format('Y-m'), now()->subMonth()->format('Y-m')] as $period) {
                Target::updateOrCreate(
                    ['user_id' => $u->id, 'period' => $period],
                    ['visits_target' => 40, 'revenue_target' => 50000],
                );
            }
        }
    }
}
