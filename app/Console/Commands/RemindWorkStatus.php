<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\WorkLog;
use App\Support\Notifier;
use App\Support\WorkStatus;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RemindWorkStatus extends Command
{
    protected $signature = 'work:remind';

    protected $description = 'Nudge checked-in employees to submit their hourly work status.';

    public function handle(): int
    {
        if (! WorkStatus::remindersEnabled()) {
            $this->info('Work-status reminders are disabled.');

            return self::SUCCESS;
        }

        // Each employee's "today"/current-hour is evaluated in THEIR timezone, so a
        // local day can fall on an adjacent server date — scan a ±1-day window and
        // decide per user inside the loop.
        $present = Attendance::with('user')
            ->whereBetween('date', [today()->subDay()->toDateString(), today()->addDay()->toDateString()])
            ->whereNotNull('check_in_at')->whereNull('check_out_at')->get();

        $sent = 0;

        foreach ($present as $att) {
            if (! $att->user) {
                continue;
            }
            $localNow = WorkStatus::nowIn($att->user->tz());

            // Only the employee's current local day, inside their working window, past their grace.
            if ($att->date->toDateString() !== $localNow->toDateString()) {
                continue;
            }
            if ($localNow->greaterThan(WorkStatus::workEnd($localNow->copy()))) {
                continue;
            }
            if ($localNow->minute < WorkStatus::graceMinutes()) {
                continue;
            }

            $slot = WorkStatus::slotFor($localNow);
            if (WorkLog::where('user_id', $att->user_id)->where('slot_at', $slot)->exists()) {
                continue; // already logged this hour
            }
            if ($att->last_reminder_slot_at && Carbon::parse($att->last_reminder_slot_at)->eq($slot)) {
                continue; // already nudged for this slot
            }

            $window = $slot->format('g A').'–'.$slot->copy()->addMinutes(WorkStatus::intervalMinutes())->format('g A');
            Notifier::send($att->user, [
                'type' => 'work_status',
                'event' => 'reminder',
                'title' => 'Work status update due',
                'message' => "Log what you're working on for {$window}.",
                'url' => '/work-log',
                'icon' => 'clock',
            ]);
            $att->update(['last_reminder_slot_at' => $slot]);
            $sent++;
        }

        $this->info("Sent {$sent} work-status reminder(s).");

        return self::SUCCESS;
    }
}
