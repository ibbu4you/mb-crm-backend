<?php

namespace App\Http\Controllers\Api;

use App\Exports\WorkLogReportExport;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Attendance;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\User;
use App\Models\WorkLog;
use App\Support\WorkStatus;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class WorkLogController extends Controller
{
    // ---- Employee side ----

    /** The signed-in user's work-status view for a day (defaults to today). */
    public function today(Request $request)
    {
        $date = $request->date('date') ? Carbon::parse($request->date('date')) : today();
        $att = Attendance::where('user_id', $request->user()->id)->whereDate('date', $date)->first();

        return response()->json($this->daySummary($request->user(), $att, $date));
    }

    /** Submit / update the current hour's work status. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(array_keys(WorkStatus::MODES))],
            'note' => ['nullable', 'string', 'max:1000'],
            'link_type' => ['nullable', Rule::in(WorkStatus::LINK_TYPES)],
            'link_id' => ['nullable', 'integer'],
        ]);

        $att = Attendance::where('user_id', $request->user()->id)->whereDate('date', today())->first();
        abort_if(! $att || ! $att->check_in_at, 422, 'Check in first to log your work status.');
        abort_if((bool) $att->check_out_at, 422, 'You have checked out for the day.');

        $slot = WorkStatus::slotFor(now());
        $late = now()->gt($slot->copy()->addMinutes(WorkStatus::intervalMinutes()));

        $log = WorkLog::updateOrCreate(
            ['user_id' => $request->user()->id, 'slot_at' => $slot],
            [
                'attendance_id' => $att->id,
                'log_date' => $slot->toDateString(),
                'mode' => $data['mode'],
                'note' => $data['note'] ?? null,
                'link_type' => $data['link_type'] ?? null,
                'link_id' => $data['link_type'] ? ($data['link_id'] ?? null) : null,
                'link_label' => $this->resolveLink($data['link_type'] ?? null, $data['link_id'] ?? null),
                'is_late' => $late,
            ],
        );

        // Clear any pending reminder for this slot.
        if ($att->last_reminder_slot_at && Carbon::parse($att->last_reminder_slot_at)->eq($slot)) {
            $att->update(['last_reminder_slot_at' => null]);
        }

        return response()->json(['data' => $this->row($log->fresh()), 'summary' => $this->daySummary($request->user(), $att, today())], 201);
    }

    /** Options for the optional "related to" link picker (lead / client / article). */
    public function linkOptions(Request $request)
    {
        $type = $request->input('type');
        $q = trim((string) $request->input('q'));

        $items = match ($type) {
            'lead' => Lead::with('contact')
                ->when($q, fn ($x) => $x->whereHas('contact', fn ($c) => $c->where('business_name', 'like', "%{$q}%")))
                ->latest()->limit(20)->get()
                ->map(fn ($l) => ['id' => $l->id, 'label' => $l->contact?->business_name ?? "Lead #{$l->id}"]),
            'client' => Contact::when($q, fn ($x) => $x->where('business_name', 'like', "%{$q}%"))
                ->latest()->limit(20)->get()
                ->map(fn ($c) => ['id' => $c->id, 'label' => $c->business_name]),
            'article' => Article::when($q, fn ($x) => $x->where('title', 'like', "%{$q}%"))
                ->latest()->limit(20)->get()
                ->map(fn ($a) => ['id' => $a->id, 'label' => $a->title]),
            default => collect(),
        };

        return response()->json(['data' => $items->values()]);
    }

    /** History list for a user + date range (self, or others with view.all). */
    public function index(Request $request)
    {
        $userId = $this->targetUserId($request);
        $from = $request->date('from') ? Carbon::parse($request->date('from')) : today();
        $to = $request->date('to') ? Carbon::parse($request->date('to')) : $from->copy();

        $logs = WorkLog::with('user')->where('user_id', $userId)
            ->whereBetween('log_date', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('slot_at')->get();

        return response()->json(['data' => $logs->map(fn ($l) => $this->row($l))]);
    }

    // ---- Admin / manager side ----

    /** Live board: everyone who checked in today, with their latest status & compliance. */
    public function board(Request $request)
    {
        $atts = Attendance::with('user')->whereDate('date', today())->whereNotNull('check_in_at')
            ->get()->sortBy(fn ($a) => $a->user?->name);

        $logs = WorkLog::whereDate('log_date', today())->get()->groupBy('user_id');

        $rows = $atts->map(function (Attendance $att) use ($logs) {
            $summary = $this->daySummary($att->user, $att, today());
            $userLogs = ($logs->get($att->user_id) ?? collect())->sortByDesc('slot_at');
            $latest = $userLogs->first();

            return [
                'user' => ['id' => $att->user->id, 'name' => $att->user->name],
                'checked_in_at' => optional($att->check_in_at)->toIso8601String(),
                'checked_out' => (bool) $att->check_out_at,
                'compliance' => $summary['compliance'] ?? 0,
                'submitted' => $summary['submitted'] ?? 0,
                'expected' => $summary['expected'] ?? 0,
                'current_filled' => $summary['current_filled'] ?? false,
                'latest' => $latest ? $this->row($latest) : null,
                'minutes_since_update' => $latest ? (int) $latest->created_at->diffInMinutes(now()) : null,
            ];
        })->values();

        return response()->json([
            'data' => $rows,
            'summary' => [
                'present' => $rows->count(),
                'logging' => $rows->filter(fn ($r) => $r['latest'] !== null)->count(),
                'avg_compliance' => $rows->count() ? (int) round($rows->avg('compliance')) : 0,
                'stale' => $rows->filter(fn ($r) => $r['minutes_since_update'] !== null && $r['minutes_since_update'] >= 90)->count(),
            ],
            'modes' => WorkStatus::modes(),
        ]);
    }

    public function report(Request $request)
    {
        [$from, $to, $label] = $this->range($request);
        $userId = $request->filled('user') ? (int) $request->input('user') : null;

        return response()->json($this->reportData($from, $to, $userId, $label));
    }

    public function export(Request $request)
    {
        [$from, $to, $label] = $this->range($request);
        $userId = $request->filled('user') ? (int) $request->input('user') : null;
        $report = $this->reportData($from, $to, $userId, $label);
        $stamp = $from->toDateString().'_'.$to->toDateString();

        if ($request->input('format') === 'pdf') {
            $pdf = Pdf::loadView('reports.work-log', ['report' => $report]);

            return $pdf->download("work-report-{$stamp}.pdf");
        }

        return Excel::download(new WorkLogReportExport($report), "work-report-{$stamp}.xlsx");
    }

    /** Edit any of the user's own entries (admins with manage can edit anyone's). */
    public function update(Request $request, WorkLog $workLog)
    {
        $this->authorizeOwn($request, $workLog);

        $data = $request->validate([
            'mode' => ['required', Rule::in(array_keys(WorkStatus::MODES))],
            'note' => ['nullable', 'string', 'max:1000'],
            'link_type' => ['nullable', Rule::in(WorkStatus::LINK_TYPES)],
            'link_id' => ['nullable', 'integer'],
        ]);

        $workLog->update([
            'mode' => $data['mode'],
            'note' => $data['note'] ?? null,
            'link_type' => $data['link_type'] ?? null,
            'link_id' => $data['link_type'] ? ($data['link_id'] ?? null) : null,
            'link_label' => $this->resolveLink($data['link_type'] ?? null, $data['link_id'] ?? null),
        ]);

        return response()->json(['data' => $this->row($workLog->fresh())]);
    }

    public function destroy(Request $request, WorkLog $workLog)
    {
        $this->authorizeOwn($request, $workLog);
        $workLog->delete();

        return response()->json(['deleted' => true]);
    }

    /** Owner may edit/delete their own entry; managers may touch anyone's. */
    private function authorizeOwn(Request $request, WorkLog $workLog): void
    {
        abort_unless(
            $workLog->user_id === $request->user()->id || $request->user()->can('work.logs.manage'),
            403,
            'You can only change your own work log entries.',
        );
    }

    // ---- helpers ----

    private function targetUserId(Request $request): int
    {
        $requested = $request->filled('user') ? (int) $request->input('user') : $request->user()->id;
        if ($requested !== $request->user()->id) {
            abort_unless($request->user()->can('work.logs.view.all'), 403);
        }

        return $requested;
    }

    private function range(Request $request): array
    {
        $scope = $request->input('scope', 'daily');
        $anchor = $request->date('date') ? Carbon::parse($request->date('date')) : today();

        return match ($scope) {
            'weekly' => [$anchor->copy()->startOfWeek(), $anchor->copy()->endOfWeek(), 'Week of '.$anchor->copy()->startOfWeek()->format('M j, Y')],
            'monthly' => [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth(), $anchor->format('F Y')],
            default => [$anchor->copy()->startOfDay(), $anchor->copy()->endOfDay(), $anchor->format('D, M j, Y')],
        };
    }

    /** Aggregate compliance + activity for every employee active in the window. */
    private function reportData(Carbon $from, Carbon $to, ?int $userId, string $label): array
    {
        $atts = Attendance::with('user')->whereNotNull('check_in_at')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($userId, fn ($q) => $q->where('user_id', $userId))->get();

        $logs = WorkLog::with('user')
            ->whereBetween('log_date', [$from->toDateString(), $to->toDateString()])
            ->when($userId, fn ($q) => $q->where('user_id', $userId))->get();

        $logsByUser = $logs->groupBy('user_id');
        $interval = WorkStatus::intervalMinutes();

        // Expected (completed) slots per user across their attendances in range.
        $expectedByUser = [];
        foreach ($atts as $att) {
            $end = Carbon::parse($att->date)->isToday() ? now() : Carbon::parse($att->date)->endOfDay();
            $expectedByUser[$att->user_id] = ($expectedByUser[$att->user_id] ?? 0) + WorkStatus::completedSlots($att, $end)->count();
        }

        $userIds = collect($expectedByUser)->keys()->merge($logsByUser->keys())->unique();
        $names = User::whereIn('id', $userIds)->pluck('name', 'id');

        $employees = $userIds->map(function ($uid) use ($expectedByUser, $logsByUser, $names, $interval) {
            $expected = (int) ($expectedByUser[$uid] ?? 0);
            $userLogs = $logsByUser->get($uid) ?? collect();
            $submitted = $userLogs->count();
            $effective = min($submitted, $expected);

            return [
                'user_id' => (int) $uid,
                'name' => $names[$uid] ?? 'User',
                'expected' => $expected,
                'submitted' => $submitted,
                'missed' => max(0, $expected - $submitted),
                'compliance' => $expected ? (int) round($effective / $expected * 100) : ($submitted ? 100 : 0),
                'hours_logged' => round($submitted * $interval / 60, 1),
            ];
        })->sortByDesc('compliance')->values();

        $modeCounts = $logs->groupBy('mode')->map->count();
        $byMode = collect(WorkStatus::MODES)->map(fn ($m, $key) => [
            'mode' => $key, 'label' => $m['label'], 'color' => $m['color'], 'count' => (int) ($modeCounts[$key] ?? 0),
        ])->values();

        $expectedTotal = array_sum($expectedByUser);
        $submittedTotal = $logs->count();

        $data = [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'label' => $label],
            'employee' => null,
            'summary' => [
                'employees' => $employees->count(),
                'expected' => $expectedTotal,
                'submitted' => $submittedTotal,
                'missed' => max(0, $expectedTotal - $submittedTotal),
                'compliance' => $expectedTotal ? (int) round(min($submittedTotal, $expectedTotal) / $expectedTotal * 100) : 0,
                'hours_logged' => round($submittedTotal * $interval / 60, 1),
            ],
            'by_mode' => $byMode,
            'employees' => $employees,
            'days' => [],
            'entries' => [],
        ];

        // A single-employee report gets a day-by-day breakdown and their entries.
        if ($userId) {
            $data['employee'] = $names[$userId] ?? 'Employee';
            $userAtts = $atts->where('user_id', $userId)->keyBy(fn ($a) => Carbon::parse($a->date)->toDateString());
            $userLogs = $logsByUser->get($userId) ?? collect();
            $logsByDate = $userLogs->groupBy(fn ($l) => $l->log_date->toDateString());

            $days = collect();
            for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addDay()) {
                $d = $cursor->toDateString();
                $att = $userAtts->get($d);
                if (! $att) {
                    continue;
                }
                $end = Carbon::parse($att->date)->isToday() ? now() : Carbon::parse($att->date)->endOfDay();
                $expected = WorkStatus::completedSlots($att, $end)->count();
                $submitted = ($logsByDate->get($d) ?? collect())->count();
                $days->push([
                    'date' => $d,
                    'expected' => $expected,
                    'submitted' => $submitted,
                    'missed' => max(0, $expected - $submitted),
                    'compliance' => $expected ? (int) round(min($submitted, $expected) / $expected * 100) : ($submitted ? 100 : 0),
                ]);
            }

            $data['days'] = $days->values();
            $data['entries'] = $userLogs->sortBy('slot_at')->map(fn ($l) => [
                'date' => $l->log_date->toDateString(),
                'hour' => $l->slot_at->format('H:i'),
                'mode' => $l->mode,
                'mode_label' => WorkStatus::label($l->mode),
                'note' => $l->note,
                'link_label' => $l->link_label,
                'is_late' => (bool) $l->is_late,
            ])->values();
        }

        return $data;
    }

    private function daySummary(?User $user, ?Attendance $att, Carbon $date): array
    {
        if (! $user || ! $att || ! $att->check_in_at) {
            return ['checked_in' => false, 'date' => $date->toDateString(), 'modes' => WorkStatus::modes()];
        }

        $now = $date->isToday() ? now() : $date->copy()->endOfDay();
        $completed = WorkStatus::completedSlots($att, $now);
        $current = WorkStatus::currentSlot($att, now());
        $logs = WorkLog::where('user_id', $user->id)->whereDate('log_date', $date)->get()
            ->keyBy(fn (WorkLog $l) => $l->slot_at->format('Y-m-d H'));

        $timeline = $completed->map(function (Carbon $slot) use ($logs) {
            $log = $logs->get($slot->format('Y-m-d H'));

            return [
                'slot_at' => $slot->toIso8601String(),
                'hour' => $slot->format('H:i'),
                'status' => $log ? 'submitted' : 'missed',
                'log' => $log ? $this->row($log) : null,
            ];
        });

        $pending = null;
        if ($current && ! $completed->contains(fn (Carbon $s) => $s->eq($current))) {
            $log = $logs->get($current->format('Y-m-d H'));
            $pending = [
                'slot_at' => $current->toIso8601String(),
                'hour' => $current->format('H:i'),
                'status' => $log ? 'submitted' : 'pending',
                'log' => $log ? $this->row($log) : null,
            ];
        }

        $submitted = $timeline->where('status', 'submitted')->count();
        $total = $timeline->count();
        $latest = $logs->sortByDesc(fn (WorkLog $l) => $l->slot_at)->first();

        return [
            'checked_in' => true,
            'checked_out' => (bool) $att->check_out_at,
            'date' => $date->toDateString(),
            'interval' => WorkStatus::intervalMinutes(),
            'current_slot' => $current?->toIso8601String(),
            'current_filled' => $current ? $logs->has($current->format('Y-m-d H')) : false,
            'compliance' => $total ? (int) round($submitted / $total * 100) : 100,
            'submitted' => $submitted,
            'expected' => $total,
            'missed' => $total - $submitted,
            'timeline' => $timeline->values(),
            'pending' => $pending,
            'latest' => $latest ? $this->row($latest) : null,
            'modes' => WorkStatus::modes(),
        ];
    }

    private function row(WorkLog $l): array
    {
        return [
            'id' => $l->id,
            'slot_at' => $l->slot_at->toIso8601String(),
            'hour' => $l->slot_at->format('H:i'),
            'mode' => $l->mode,
            'mode_label' => WorkStatus::label($l->mode),
            'mode_color' => WorkStatus::color($l->mode),
            'note' => $l->note,
            'link_type' => $l->link_type,
            'link_id' => $l->link_id,
            'link_label' => $l->link_label,
            'is_late' => (bool) $l->is_late,
            'user' => $l->relationLoaded('user') && $l->user ? ['id' => $l->user->id, 'name' => $l->user->name] : null,
            'created_at' => $l->created_at->toIso8601String(),
        ];
    }

    private function resolveLink(?string $type, ?int $id): ?string
    {
        if (! $type || ! $id) {
            return null;
        }

        return match ($type) {
            'lead' => optional(Lead::with('contact')->find($id))->contact?->business_name,
            'article' => optional(Article::find($id))->title,
            'client' => optional(Contact::find($id))->business_name,
            default => null,
        };
    }
}
