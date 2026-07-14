<?php

namespace App\Console\Commands;

use App\Models\Lead;
use Illuminate\Console\Command;

class FlagDormantLeads extends Command
{
    protected $signature = 'leads:flag-dormant';

    protected $description = 'Flag active leads with no activity in 30 days as dormant (and revive recently-touched ones).';

    public function handle(): int
    {
        $cutoff = now()->subDays(Lead::DORMANT_DAYS);
        $flagged = 0;
        $revived = 0;

        // Stale actives -> dormant
        $flagged = Lead::where('status', 'active')
            ->where(function ($q) use ($cutoff) {
                $q->where('last_activity_at', '<', $cutoff)
                    ->orWhere(fn ($w) => $w->whereNull('last_activity_at')->where('created_at', '<', $cutoff));
            })
            ->update(['status' => 'dormant']);

        // Recently-touched dormants -> active
        $revived = Lead::where('status', 'dormant')
            ->where('last_activity_at', '>=', $cutoff)
            ->update(['status' => 'active']);

        $this->info("Dormant flagged: {$flagged}, revived: {$revived}");

        return self::SUCCESS;
    }
}
