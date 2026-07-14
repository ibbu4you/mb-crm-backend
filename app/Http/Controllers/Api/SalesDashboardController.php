<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Target;
use App\Models\User;
use App\Models\Visit;
use App\Support\Metrics;
use App\Support\Pipeline;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesDashboardController extends Controller
{
    public function index(Request $request)
    {
        $period = in_array($request->input('period'), Metrics::PERIODS, true) ? $request->input('period') : 'monthly';
        [$start, $end] = Metrics::range($period);
        $teamView = $request->user()->can('sales.reports.view.all');
        $uid = $request->user()->id;

        // Focus: team users can drill into a single salesperson via ?user_id.
        $focusId = $teamView ? ($request->input('user_id') ?: null) : $uid;
        $focusId = $focusId ? (int) $focusId : null;

        $visitScope = fn () => Visit::whereBetween('visit_date', [$start, $end])->when($focusId, fn ($q) => $q->where('user_id', $focusId));
        $dealScope = fn () => Deal::whereBetween('closed_at', [$start, $end])->when($focusId, fn ($q) => $q->where('user_id', $focusId));
        $leadScope = fn () => Lead::query()->when($focusId, fn ($q) => $q->where('owner_id', $focusId));

        $v = $visitScope();
        $kpis = [
            'visits' => (clone $v)->count(),
            'decision_makers' => (clone $v)->where('decision_maker_met', true)->count(),
            'interested' => (clone $v)->where('interested', true)->count(),
            'follow_ups_done' => (clone $v)->where('follow_up_done', true)->count(),
            'proposals' => (clone $v)->where('visit_level', 'proposal')->count(),
            'revenue_potential' => (float) (clone $v)->sum('revenue_potential'),
            'deals_won' => (clone $dealScope())->where('outcome', 'won')->count(),
            'deals_lost' => (clone $dealScope())->where('outcome', 'lost')->count(),
            'revenue_actual' => (float) (clone $dealScope())->where('outcome', 'won')->sum('actual_revenue'),
        ];

        $stageCounts = $leadScope()->select('pipeline_stage', DB::raw('count(*) as c'))->groupBy('pipeline_stage')->pluck('c', 'pipeline_stage');
        $funnel = collect(Pipeline::STAGES)->map(fn ($s) => [
            'stage' => $s, 'label' => Pipeline::label($s), 'color' => Pipeline::META[$s]['color'], 'count' => (int) ($stageCounts[$s] ?? 0),
        ])->values();

        // Trends — visits + revenue (won) per bucket
        $buckets = Metrics::trendBuckets($period);
        $isYear = $period === 'yearly';
        $visitRows = (clone $visitScope())
            ->when($isYear, fn ($q) => $q->selectRaw("DATE_FORMAT(visit_date,'%Y-%m') as k, count(*) as c"), fn ($q) => $q->selectRaw('DATE(visit_date) as k, count(*) as c'))
            ->groupBy('k')->pluck('c', 'k');
        $revRows = (clone $dealScope())->where('outcome', 'won')
            ->when($isYear, fn ($q) => $q->selectRaw("DATE_FORMAT(closed_at,'%Y-%m') as k, sum(actual_revenue) as c"), fn ($q) => $q->selectRaw('DATE(closed_at) as k, sum(actual_revenue) as c'))
            ->groupBy('k')->pluck('c', 'k');
        $trend = collect($buckets)->map(fn ($b) => [
            'label' => $b['label'],
            'count' => (int) ($visitRows[$b['key']] ?? 0),
            'revenue' => (float) ($revRows[$b['key']] ?? 0),
        ]);

        $leaderboard = [];
        if ($teamView) {
            $leaderboard = Visit::whereBetween('visit_date', [$start, $end])
                ->select('user_id', DB::raw('count(*) as visits'), DB::raw('sum(revenue_potential) as potential'))
                ->groupBy('user_id')->with('salesperson:id,name')->get()
                ->sortByDesc('potential')->take(8)
                ->map(fn ($r) => ['name' => $r->salesperson?->name ?? 'Unknown', 'visits' => (int) $r->visits, 'potential' => (float) $r->potential])->values();
        }

        $activity = Visit::with(['lead.contact', 'salesperson'])
            ->when($focusId, fn ($q) => $q->where('user_id', $focusId))
            ->latest('visit_date')->latest()->limit(8)->get()
            ->map(fn ($x) => [
                'business_name' => $x->lead?->contact?->business_name, 'stage_label' => Pipeline::label($x->visit_level),
                'visit_date' => $x->visit_date->toDateString(), 'salesperson' => $x->salesperson?->name, 'revenue_potential' => (float) $x->revenue_potential,
            ]);

        // Due follow-ups (pending, due today or overdue)
        $dueFollowUps = FollowUp::with('lead.contact')->where('status', 'pending')->whereDate('due_date', '<=', today())
            ->when($focusId, fn ($q) => $q->where('user_id', $focusId))
            ->orderBy('due_date')->limit(6)->get()
            ->map(fn ($f) => ['business_name' => $f->lead?->contact?->business_name, 'due_date' => $f->due_date->toDateString(), 'note' => $f->note]);

        // Target rings for the focused person (or the viewer)
        $ringUser = $focusId ?? $uid;
        $target = Target::where('user_id', $ringUser)->where('period', Target::currentPeriod())->first();
        $rings = [
            'visits_target' => $target?->visits_target,
            'visits_actual' => Visit::where('user_id', $ringUser)->whereYear('visit_date', now()->year)->whereMonth('visit_date', now()->month)->count(),
            'revenue_target' => $target ? (float) $target->revenue_target : null,
            'revenue_actual' => (float) Deal::where('user_id', $ringUser)->where('outcome', 'won')->whereYear('closed_at', now()->year)->whereMonth('closed_at', now()->month)->sum('actual_revenue'),
        ];

        // Salespeople list for the focus dropdown
        $salespeople = $teamView
            ? User::whereHas('roles', fn ($r) => $r->whereIn('name', ['Salesperson', 'Manager']))
                ->orWhereHas('permissions', fn ($p) => $p->where('name', 'sales.visits.log'))
                ->get(['id', 'name'])->unique('id')->values()
            : [];

        return response()->json([
            'period' => $period, 'team_view' => $teamView, 'focus_id' => $focusId,
            'salespeople' => $salespeople,
            'kpis' => $kpis, 'funnel' => $funnel, 'trend' => $trend,
            'leaderboard' => $leaderboard, 'activity' => $activity, 'due_follow_ups' => $dueFollowUps, 'rings' => $rings,
        ]);
    }
}
