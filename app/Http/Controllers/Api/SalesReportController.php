<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use App\Models\Visit;
use App\Support\Metrics;
use App\Support\Pipeline;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesReportController extends Controller
{
    private function period(Request $request): array
    {
        $period = in_array($request->input('period'), Metrics::PERIODS, true) ? $request->input('period') : 'monthly';

        return [$period, ...Metrics::range($period)];
    }

    private function rows($start, $end): \Illuminate\Support\Collection
    {
        // Salespeople who have any visits, plus users with the log permission.
        $userIds = Visit::whereBetween('visit_date', [$start, $end])->distinct()->pluck('user_id');
        $users = User::whereIn('id', $userIds)->orWhereHas('roles', fn ($r) => $r->whereIn('name', ['Salesperson', 'Manager']))->get()->unique('id');

        return $users->map(function (User $u) use ($start, $end) {
            $v = Visit::where('user_id', $u->id)->whereBetween('visit_date', [$start, $end]);
            $d = Deal::where('user_id', $u->id)->whereBetween('closed_at', [$start, $end]);

            return [
                'user' => $u->name,
                'visits' => (clone $v)->count(),
                'decision_makers' => (clone $v)->where('decision_maker_met', true)->count(),
                'interested' => (clone $v)->where('interested', true)->count(),
                'follow_ups_done' => (clone $v)->where('follow_up_done', true)->count(),
                'proposals' => (clone $v)->where('visit_level', 'proposal')->count(),
                'revenue_potential' => (float) (clone $v)->sum('revenue_potential'),
                'deals_won' => (clone $d)->where('outcome', 'won')->count(),
                'revenue_actual' => (float) (clone $d)->where('outcome', 'won')->sum('actual_revenue'),
            ];
        })->values();
    }

    public function index(Request $request)
    {
        [$period, $start, $end] = $this->period($request);
        $rows = $this->rows($start, $end);

        $funnel = collect(Pipeline::STAGES)->map(fn ($s) => [
            'label' => Pipeline::label($s),
            'count' => Lead::where('pipeline_stage', $s)->count(),
        ]);

        return response()->json([
            'period' => $period,
            'rows' => $rows,
            'funnel' => $funnel,
            'totals' => [
                'visits' => $rows->sum('visits'),
                'interested' => $rows->sum('interested'),
                'proposals' => $rows->sum('proposals'),
                'revenue_potential' => $rows->sum('revenue_potential'),
                'deals_won' => $rows->sum('deals_won'),
                'revenue_actual' => $rows->sum('revenue_actual'),
            ],
        ]);
    }

    public function export(Request $request)
    {
        [$period, $start, $end] = $this->period($request);
        $rows = $this->rows($start, $end);
        $filename = "sales-report-{$period}-".now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Salesperson', 'Visits', 'Decision Makers Met', 'Interested', 'Follow-ups Done', 'Proposals', 'Revenue Potential (RM)', 'Deals Won', 'Revenue Actual (RM)']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['user'], $r['visits'], $r['decision_makers'], $r['interested'], $r['follow_ups_done'], $r['proposals'], $r['revenue_potential'], $r['deals_won'], $r['revenue_actual']]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
