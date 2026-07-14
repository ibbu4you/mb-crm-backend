<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Leave;
use App\Models\Setting;
use App\Models\User;
use App\Support\Notifier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeaveController extends Controller
{
    private const TYPES = ['annual', 'sick', 'unpaid', 'emergency'];

    private function entitlements(): array
    {
        return [
            'annual' => (int) Setting::get('leave.annual_days', 14),
            'sick' => (int) Setting::get('leave.sick_days', 14),
            'unpaid' => null,
            'emergency' => null,
        ];
    }

    /** Leave types + this-year balances for the current user. */
    public function catalog(Request $request)
    {
        return response()->json([
            'types' => self::TYPES,
            'balances' => $this->balances($request->user()->id),
        ]);
    }

    public function mine(Request $request)
    {
        $leaves = Leave::where('user_id', $request->user()->id)->with('reviewer')->latest()->get()
            ->map(fn (Leave $l) => $this->row($l));

        return response()->json(['data' => $leaves, 'balances' => $this->balances($request->user()->id)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(self::TYPES)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'half_day' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $start = Carbon::parse($data['start_date']);
        $end = Carbon::parse($data['end_date']);
        $halfDay = (bool) ($data['half_day'] ?? false) && $start->isSameDay($end);
        $days = $halfDay ? 0.5 : $start->diffInDays($end) + 1;

        $leave = Leave::create([
            'user_id' => $request->user()->id,
            'type' => $data['type'],
            'start_date' => $start,
            'end_date' => $end,
            'days' => $days,
            'half_day' => $halfDay,
            'reason' => $data['reason'] ?? null,
            'status' => 'pending',
        ]);

        Notifier::toPermission('hrms.leave.approve', [
            'type' => 'leave', 'event' => 'created',
            'title' => 'Leave request',
            'message' => "{$request->user()->name} requested {$days} day(s) ".ucfirst($data['type']).' leave',
            'url' => '/attendance/leave-requests', 'icon' => 'leave',
        ], $request->user()->id);

        return response()->json(['data' => $this->row($leave->fresh('reviewer'))], 201);
    }

    public function cancel(Request $request, Leave $leave)
    {
        abort_unless($leave->user_id === $request->user()->id, 403);
        abort_if($leave->status !== 'pending', 422, 'Only pending requests can be cancelled.');
        $leave->update(['status' => 'cancelled']);

        return response()->json(['data' => $this->row($leave)]);
    }

    // --- Approver side ---

    public function index(Request $request)
    {
        $q = Leave::with(['user', 'reviewer'])->latest();
        if ($status = $request->input('status')) {
            $q->where('status', $status);
        }
        if ($type = $request->input('type')) {
            $q->where('type', $type);
        }
        $counts = Leave::selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');

        return response()->json([
            'data' => $q->get()->map(fn (Leave $l) => $this->row($l, true)),
            'counts' => [
                'pending' => (int) ($counts['pending'] ?? 0),
                'approved' => (int) ($counts['approved'] ?? 0),
                'rejected' => (int) ($counts['rejected'] ?? 0),
            ],
        ]);
    }

    public function approve(Request $request, Leave $leave)
    {
        return $this->review($request, $leave, 'approved');
    }

    public function reject(Request $request, Leave $leave)
    {
        return $this->review($request, $leave, 'rejected');
    }

    private function review(Request $request, Leave $leave, string $status)
    {
        abort_if($leave->status !== 'pending', 422, 'This request has already been reviewed.');
        $note = $request->validate(['note' => ['nullable', 'string', 'max:255']])['note'] ?? null;

        $leave->update([
            'status' => $status,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        Notifier::send(User::find($leave->user_id), [
            'type' => 'leave', 'event' => $status,
            'title' => 'Leave '.$status,
            'message' => 'Your '.ucfirst($leave->type).' leave was '.$status.($note ? ": {$note}" : ''),
            'url' => '/attendance', 'icon' => 'leave',
        ], $request->user()->id);

        return response()->json(['data' => $this->row($leave->fresh(['user', 'reviewer']), true)]);
    }

    private function balances(int $userId): array
    {
        $used = Leave::where('user_id', $userId)->where('status', 'approved')->whereYear('start_date', now()->year)
            ->selectRaw('type, SUM(days) as d')->groupBy('type')->pluck('d', 'type');
        $ent = $this->entitlements();

        return collect(self::TYPES)->map(function ($t) use ($used, $ent) {
            $u = (float) ($used[$t] ?? 0);

            return [
                'type' => $t,
                'entitlement' => $ent[$t],
                'used' => $u,
                'remaining' => $ent[$t] !== null ? max(0, $ent[$t] - $u) : null,
            ];
        })->all();
    }

    private function row(Leave $l, bool $withUser = false): array
    {
        return array_filter([
            'id' => $l->id,
            'user' => $withUser ? $l->user?->only('id', 'name') : null,
            'type' => $l->type,
            'start_date' => $l->start_date->toDateString(),
            'end_date' => $l->end_date->toDateString(),
            'days' => (float) $l->days,
            'half_day' => $l->half_day,
            'reason' => $l->reason,
            'status' => $l->status,
            'reviewed_by' => $l->reviewer?->name,
            'reviewed_at' => optional($l->reviewed_at)->toIso8601String(),
            'review_note' => $l->review_note,
            'created_at' => $l->created_at->toIso8601String(),
        ], fn ($v) => $v !== null);
    }
}
