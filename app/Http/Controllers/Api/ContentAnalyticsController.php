<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Support\ArticleWorkflow as WF;
use Illuminate\Support\Facades\DB;

class ContentAnalyticsController extends Controller
{
    public function __invoke()
    {
        $byStage = Article::select('current_stage', DB::raw('count(*) as c'))->groupBy('current_stage')->pluck('c', 'current_stage');
        $stages = collect(WF::all())->map(fn ($s) => ['label' => WF::label($s), 'color' => WF::META[$s]['color'] ?? '#1d4ed8', 'count' => (int) ($byStage[$s] ?? 0)]);

        // Throughput: published per month, last 6 months
        $throughput = collect(range(5, 0))->map(function ($back) {
            $d = now()->subMonths($back);

            return [
                'label' => $d->format('M'),
                'count' => Article::where('current_stage', WF::PUBLISHED)->whereYear('published_at', $d->year)->whereMonth('published_at', $d->month)->count(),
            ];
        });

        $byPriority = Article::select('priority', DB::raw('count(*) as c'))->groupBy('priority')->pluck('c', 'priority');

        $activeStages = ['assigned', 'in_progress', 'revisions'];
        $published = (int) ($byStage[WF::PUBLISHED] ?? 0);

        // Average turnaround: submission → publish (days) on published articles.
        $avgDays = Article::where('current_stage', WF::PUBLISHED)->whereNotNull('submitted_at')->whereNotNull('published_at')
            ->selectRaw('avg(datediff(published_at, submitted_at)) as a')->value('a');

        // Writer leaderboard.
        $writers = DB::table('articles')
            ->join('users', 'users.id', '=', 'articles.tech_writer_id')
            ->whereNull('articles.deleted_at')
            ->select('users.name', DB::raw('count(*) as total'),
                DB::raw("sum(case when articles.current_stage = 'published' then 1 else 0 end) as published"),
                DB::raw("sum(case when articles.current_stage in ('assigned','in_progress','revisions') then 1 else 0 end) as active"))
            ->groupBy('users.id', 'users.name')->orderByDesc('total')->limit(10)->get()
            ->map(fn ($r) => ['name' => $r->name, 'total' => (int) $r->total, 'published' => (int) $r->published, 'active' => (int) $r->active]);

        // Top clients by article count.
        $topClients = DB::table('articles')
            ->join('contacts', 'contacts.id', '=', 'articles.client_id')
            ->whereNull('articles.deleted_at')
            ->select('contacts.business_name as name', DB::raw('count(*) as count'))
            ->groupBy('contacts.id', 'contacts.business_name')->orderByDesc('count')->limit(8)->get()
            ->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->count]);

        return response()->json([
            'by_stage' => $stages,
            'throughput' => $throughput,
            'by_priority' => ['low' => (int) ($byPriority['low'] ?? 0), 'medium' => (int) ($byPriority['medium'] ?? 0), 'high' => (int) ($byPriority['high'] ?? 0)],
            'totals' => ['articles' => Article::count(), 'published' => $published],
            'active' => (int) collect($activeStages)->sum(fn ($s) => (int) ($byStage[$s] ?? 0)),
            'due_this_week' => Article::whereNotNull('deadline')->whereBetween('deadline', [today(), today()->copy()->addDays(7)])->where('current_stage', '!=', WF::PUBLISHED)->count(),
            'overdue' => Article::whereNotNull('deadline')->whereDate('deadline', '<', today())->where('current_stage', '!=', WF::PUBLISHED)->count(),
            'avg_days' => $avgDays !== null ? round((float) $avgDays, 1) : null,
            'writers' => $writers,
            'top_clients' => $topClients,
        ]);
    }
}
