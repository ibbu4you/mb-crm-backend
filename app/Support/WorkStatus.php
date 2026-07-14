<?php

namespace App\Support;

use App\Models\Attendance;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Hourly work-status helpers: the status modes and the slot math that turns an
 * attendance record (check-in → check-out) into the set of expected hourly slots
 * used for reminders and compliance.
 */
class WorkStatus
{
    public const MODES = [
        'working' => ['label' => 'Working', 'color' => '#16A34A'],
        'meeting' => ['label' => 'Meeting', 'color' => '#2563EB'],
        'break' => ['label' => 'Break', 'color' => '#D97706'],
        'blocked' => ['label' => 'Blocked', 'color' => '#E5484D'],
    ];

    public const LINK_TYPES = ['lead', 'article', 'client'];

    public static function modes(): array
    {
        return collect(self::MODES)->map(fn ($m, $key) => ['key' => $key, 'label' => $m['label'], 'color' => $m['color']])->values()->all();
    }

    public static function label(string $mode): string
    {
        return self::MODES[$mode]['label'] ?? ucfirst($mode);
    }

    public static function color(string $mode): string
    {
        return self::MODES[$mode]['color'] ?? '#64748B';
    }

    public static function intervalMinutes(): int
    {
        return (int) Setting::get('work.reminder_interval_minutes', 60);
    }

    public static function graceMinutes(): int
    {
        return (int) Setting::get('work.grace_minutes', 15);
    }

    public static function remindersEnabled(): bool
    {
        return (bool) Setting::get('work.reminder_enabled', true);
    }

    /** Hard end-of-day cap so a forgotten check-out doesn't spawn infinite slots. */
    public static function workEnd(Carbon $date): Carbon
    {
        $end = (string) Setting::get('work.work_end_time', '18:00');
        [$h, $m] = array_pad(explode(':', $end), 2, '0');

        return $date->copy()->setTime((int) $h, (int) $m, 0);
    }

    /** The hour-bucket a given moment falls into (top of the hour). */
    public static function slotFor(Carbon $moment): Carbon
    {
        return $moment->copy()->startOfHour();
    }

    /**
     * Completed hourly slots for an attendance up to `$now` — the hours that have
     * fully elapsed within the presence window. These form the compliance denominator.
     *
     * @return Collection<int, Carbon>
     */
    public static function completedSlots(Attendance $attendance, ?Carbon $now = null): Collection
    {
        $now ??= now();
        if (! $attendance->check_in_at) {
            return collect();
        }

        $slot = Carbon::parse($attendance->check_in_at)->startOfHour();
        $end = $attendance->check_out_at ? Carbon::parse($attendance->check_out_at) : $now;
        $end = $end->min(self::workEnd(Carbon::parse($attendance->date)));

        $slots = collect();
        while ($slot->copy()->addMinutes(self::intervalMinutes())->lte($end)) {
            $slots->push($slot->copy());
            $slot->addMinutes(self::intervalMinutes());
        }

        return $slots;
    }

    /** The in-progress slot (current hour) while still checked in — "pending", not missed. */
    public static function currentSlot(Attendance $attendance, ?Carbon $now = null): ?Carbon
    {
        $now ??= now();
        if (! $attendance->check_in_at || $attendance->check_out_at) {
            return null;
        }

        return self::slotFor($now);
    }
}
