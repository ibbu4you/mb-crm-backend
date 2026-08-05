<?php

namespace App\Console\Commands;

use App\Models\WorkLog;
use App\Support\Timezones;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off repair: work-log slots recorded while the live server ran in UTC were
 * stored 8h behind (for KL staff). Each row's `created_at` is a real instant, so we
 * re-derive the correct hourly slot in the employee's timezone and rewrite slot_at /
 * log_date. Rows that are already correct are skipped; collisions (a correct row
 * already occupies the target slot) are reported and left untouched. Dry-run by
 * default — pass --apply to write.
 */
class FixWorkLogTimes extends Command
{
    protected $signature = 'work:fix-times {--apply : Write the changes (otherwise dry-run)} {--from= : Only rows with log_date >= YYYY-MM-DD} {--user= : Restrict to one user id}';

    protected $description = 'Correct work-log slot times recorded while the server ran in UTC.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $logs = WorkLog::with('user')
            ->when($this->option('from'), fn ($q, $f) => $q->where('log_date', '>=', $f))
            ->when($this->option('user'), fn ($q, $u) => $q->where('user_id', (int) $u))
            ->orderBy('user_id')->orderBy('slot_at')->get();

        // Track which (user_id, slot_at) pairs are occupied so we never create a
        // duplicate that would violate the per-hour uniqueness rule.
        $occupied = [];
        foreach ($logs as $l) {
            $occupied[$l->user_id][$l->slot_at->format('Y-m-d H:i:s')] = $l->id;
        }

        $planned = [];
        $conflicts = [];
        $ok = 0;

        foreach ($logs as $l) {
            $tz = $l->user?->tz() ?? Timezones::business();

            // created_at is the true submission instant (stored in the server's tz,
            // which Eloquent already knows). Its local hour in the employee's zone is
            // the correct slot.
            $correct = $l->created_at->copy()->setTimezone($tz)->startOfHour();
            $new = $correct->format('Y-m-d H:i:s');
            $current = $l->slot_at->format('Y-m-d H:i:s');

            if ($new === $current) {
                $ok++;

                continue;
            }

            $holder = $occupied[$l->user_id][$new] ?? null;
            if ($holder && $holder !== $l->id) {
                $conflicts[] = [$l, $new, $holder];

                continue;
            }

            $planned[] = [$l, $current, $new, $correct->toDateString()];
            unset($occupied[$l->user_id][$current]);
            $occupied[$l->user_id][$new] = $l->id;
        }

        $this->info(($apply ? 'APPLYING' : 'DRY RUN').': '.$logs->count()." scanned · {$ok} already correct · ".count($planned).' to fix · '.count($conflicts).' conflicts');

        foreach ($planned as [$l, $current, $new, $date]) {
            $this->line(sprintf('  #%-5d u%-3d %-20s  %s  ->  %s', $l->id, $l->user_id, \Illuminate\Support\Str::limit((string) optional($l->user)->name, 18), substr($current, 11, 5), substr($new, 11, 5).'  ('.$date.')'));
        }
        foreach ($conflicts as [$l, $new, $holder]) {
            $this->warn(sprintf('  CONFLICT #%d (u%d) wants %s but #%d is already there — skipped', $l->id, $l->user_id, substr($new, 11, 5), $holder));
        }

        if (! $apply) {
            $this->newLine();
            $this->info('Nothing written. Re-run with --apply to save these changes.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($planned) {
            foreach ($planned as [$l, , $new, $date]) {
                DB::table('work_logs')->where('id', $l->id)->update(['slot_at' => $new, 'log_date' => $date]);
            }
        });

        $this->info('Done — fixed '.count($planned).' row(s).');

        return self::SUCCESS;
    }
}
