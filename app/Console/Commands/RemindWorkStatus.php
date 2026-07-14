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

        $now = now();
        // Only remind within the working window (before the hard end-of-day cap).
        if ($now->greaterThan(WorkStatus::workEnd($now->copy()))) {
            return self::SUCCESS;
        }

        $slot = WorkStatus::slotFor($now);
        // Give employees a grace window into the hour before nudging.
        if ($now->minute < WorkStatus::graceMinutes()) {
            return self::SUCCESS;
        }

        $present = Attendance::with('user')->whereDate('date', today())
            ->whereNotNull('check_in_at')->whereNull('check_out_at')->get();

        $filled = WorkLog::where('slot_at', $slot)->pluck('user_id')->flip();
        $sent = 0;

        foreach ($present as $att) {
            if (! $att->user || $filled->has($att->user_id)) {
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

        $this->info("Sent {$sent} work-status reminder(s) for slot {$slot->format('H:i')}.");

        return self::SUCCESS;
    }
}
