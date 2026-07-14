<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\OfficeLocation;
use App\Models\Setting;
use App\Models\User;
use App\Services\GeocodingService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function __construct(private GeocodingService $geo) {}

    /** Today's own record + config, for the check-in screen. */
    public function today(Request $request)
    {
        $a = Attendance::where('user_id', $request->user()->id)->whereDate('date', today())->first();

        return response()->json([
            'attendance' => $a ? $this->row($a) : null,
            'work_start_time' => Setting::get('attendance.work_start_time', '09:00'),
            'offices' => OfficeLocation::where('is_active', true)->get(['id', 'name', 'lat', 'lng', 'radius_m']),
        ]);
    }

    public function checkIn(Request $request)
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'integer'],
            'address' => ['nullable', 'string', 'max:500'],
            'photo' => ['nullable', 'image', 'max:8192'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $existing = Attendance::where('user_id', $request->user()->id)->whereDate('date', today())->first();
        abort_if($existing && $existing->check_in_at, 422, 'You have already checked in today.');

        [$onSite, $office] = $this->resolveGeofence($data['lat'], $data['lng']);
        $address = ($data['address'] ?? null) ?: $this->geo->reverse($data['lat'], $data['lng']);
        $status = $this->clockStatus(now());

        $attendance = $existing ?: new Attendance(['user_id' => $request->user()->id, 'date' => today()]);
        $attendance->fill([
            'check_in_at' => now(),
            'in_lat' => $data['lat'], 'in_lng' => $data['lng'], 'in_accuracy' => $data['accuracy'] ?? null,
            'in_address' => $address, 'note' => $data['note'] ?? null,
            'status' => $status, 'on_site' => $onSite, 'office_location_id' => $office?->id,
        ]);
        if ($request->hasFile('photo')) {
            $attendance->in_photo_path = $request->file('photo')->store('attendance', 'public');
        }
        $attendance->save();

        return response()->json(['data' => $this->row($attendance)], 201);
    }

    public function checkOut(Request $request)
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'address' => ['nullable', 'string', 'max:500'],
            'photo' => ['nullable', 'image', 'max:8192'],
        ]);

        $a = Attendance::where('user_id', $request->user()->id)->whereDate('date', today())->first();
        abort_if(! $a || ! $a->check_in_at, 422, 'You have not checked in today.');
        abort_if($a->check_out_at, 422, 'You have already checked out today.');

        $a->check_out_at = now();
        $a->out_lat = $data['lat'];
        $a->out_lng = $data['lng'];
        $a->out_address = ($data['address'] ?? null) ?: $this->geo->reverse($data['lat'], $data['lng']);
        $a->work_minutes = $a->check_in_at->diffInMinutes(now());
        if ($request->hasFile('photo')) {
            $a->out_photo_path = $request->file('photo')->store('attendance', 'public');
        }
        // Half-day if short and not already flagged late.
        $halfDay = (int) Setting::get('attendance.half_day_minutes', 240);
        if ($a->status === 'present' && $a->work_minutes < $halfDay) {
            $a->status = 'half_day';
        }
        $a->save();

        return response()->json(['data' => $this->row($a)]);
    }

    /** Own history. */
    public function mine(Request $request)
    {
        $items = Attendance::where('user_id', $request->user()->id)->latest('date')->limit(60)->get()->map(fn ($a) => $this->row($a));

        return response()->json(['data' => $items]);
    }

    /** Own monthly summary + full record set for a given month (YYYY-MM). */
    public function summary(Request $request)
    {
        try {
            $ref = Carbon::createFromFormat('Y-m', (string) $request->input('month'))->startOfMonth();
        } catch (\Throwable) {
            $ref = now()->startOfMonth();
        }

        $records = Attendance::where('user_id', $request->user()->id)
            ->whereYear('date', $ref->year)->whereMonth('date', $ref->month)
            ->orderByDesc('date')->get();

        $checkedIn = $records->whereNotNull('check_in_at');
        $minutes = (int) $records->sum('work_minutes');

        return response()->json([
            'month' => $ref->format('Y-m'),
            'label' => $ref->format('F Y'),
            'stats' => [
                'present' => $checkedIn->count(),
                'work_minutes' => $minutes,
                'work_hours' => round($minutes / 60, 1),
                'late' => $records->where('status', 'late')->count(),
                'half_day' => $records->where('status', 'half_day')->count(),
                'on_site' => $records->where('on_site', true)->count(),
                'field' => $checkedIn->where('on_site', false)->count(),
            ],
            'records' => $records->map(fn ($a) => $this->row($a))->values(),
        ]);
    }

    /** Team register for a date (managers). */
    public function register(Request $request)
    {
        $date = $request->input('date', today()->toDateString());
        $items = Attendance::with(['user', 'office'])->whereDate('date', $date)->latest('check_in_at')->get()->map(fn ($a) => $this->row($a, true));

        return response()->json(['date' => $date, 'data' => $items]);
    }

    /**
     * Team attendance sheet for a month or week — every active employee with a
     * per-day status matrix + period totals. Powers the admin history view.
     */
    public function team(Request $request)
    {
        $period = $request->input('period') === 'week' ? 'week' : 'month';
        try {
            $ref = Carbon::parse((string) $request->input('date', today()));
        } catch (\Throwable) {
            $ref = today();
        }

        if ($period === 'week') {
            $start = $ref->copy()->startOfWeek();
            $end = $ref->copy()->endOfWeek();
            $label = $start->format('M j').' – '.$end->format('M j, Y');
        } else {
            $start = $ref->copy()->startOfMonth();
            $end = $ref->copy()->endOfMonth();
            $label = $start->format('F Y');
        }

        $days = [];
        for ($d = $start->copy(); $d <= $end; $d->addDay()) {
            $days[] = ['date' => $d->toDateString(), 'dow' => $d->format('D'), 'day' => (int) $d->format('j'), 'weekend' => $d->isWeekend()];
        }
        $dayKeys = array_column($days, 'date');

        $records = Attendance::whereBetween('date', [$start->toDateString(), $end->toDateString()])->get()->groupBy('user_id');
        $employees = User::where('is_active', true)->orderBy('name')->get()->map(function (User $u) use ($records, $dayKeys) {
            $recs = ($records->get($u->id) ?? collect());
            $byDate = $recs->keyBy(fn ($r) => $r->date->toDateString());
            $checkedIn = $recs->whereNotNull('check_in_at');
            $minutes = (int) $recs->sum('work_minutes');

            $cells = [];
            foreach ($dayKeys as $d) {
                $r = $byDate->get($d);
                $cells[$d] = $r ? [
                    'status' => $r->status,
                    'on_site' => (bool) $r->on_site,
                    'in' => optional($r->check_in_at)->format('H:i'),
                    'out' => optional($r->check_out_at)->format('H:i'),
                ] : null;
            }

            return [
                'id' => $u->id,
                'name' => $u->name,
                'avatar_url' => $u->avatar_url,
                'present' => $checkedIn->count(),
                'work_hours' => round($minutes / 60, 1),
                'late' => $recs->where('status', 'late')->count(),
                'half_day' => $recs->where('status', 'half_day')->count(),
                'field' => $checkedIn->where('on_site', false)->count(),
                'cells' => $cells,
            ];
        });

        return response()->json([
            'period' => $period,
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'label' => $label,
            'days' => $days,
            'employees' => $employees->values(),
            'totals' => [
                'employees' => $employees->count(),
                'present_today' => Attendance::whereDate('date', today())->whereNotNull('check_in_at')->count(),
            ],
        ]);
    }

    private function resolveGeofence(float $lat, float $lng): array
    {
        foreach (OfficeLocation::where('is_active', true)->get() as $office) {
            if (Attendance::distance($lat, $lng, $office->lat, $office->lng) <= $office->radius_m) {
                return [true, $office];
            }
        }

        return [false, null];
    }

    private function clockStatus(Carbon $at): string
    {
        $start = Setting::get('attendance.work_start_time', '09:00');
        $grace = (int) Setting::get('attendance.late_grace_minutes', 15);
        $threshold = Carbon::parse($at->toDateString().' '.$start)->addMinutes($grace);

        return $at->gt($threshold) ? 'late' : 'present';
    }

    private function row(Attendance $a, bool $withUser = false): array
    {
        return array_filter([
            'id' => $a->id,
            'date' => $a->date->toDateString(),
            'user' => $withUser ? $a->user?->only('id', 'name') : null,
            'check_in_at' => $a->check_in_at,
            'check_out_at' => $a->check_out_at,
            'status' => $a->status,
            'on_site' => $a->on_site,
            'office' => $a->office?->name,
            'work_minutes' => $a->work_minutes,
            'in_lat' => $a->in_lat, 'in_lng' => $a->in_lng, 'in_accuracy' => $a->in_accuracy, 'in_address' => $a->in_address,
            'out_lat' => $a->out_lat, 'out_lng' => $a->out_lng, 'out_address' => $a->out_address,
            'in_photo_url' => $a->in_photo_url, 'out_photo_url' => $a->out_photo_url,
            'note' => $a->note,
        ], fn ($v) => $v !== null);
    }
}
