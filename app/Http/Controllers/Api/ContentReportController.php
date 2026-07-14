<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ViralPackage;
use App\Support\ArticleWorkflow as WF;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContentReportController extends Controller
{
    /** Resolve the ?month=YYYY-MM window (null = all-time / lifetime). */
    private function range(?string $month): array
    {
        if (! $month || $month === 'all') {
            return [null, null];
        }
        try {
            $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        } catch (\Throwable) {
            return [null, null];
        }

        return [$start, $start->copy()->endOfMonth()];
    }

    /** KPI tiles — scoped to the selected month, or lifetime when none. */
    private function kpis(?Carbon $start, ?Carbon $end): array
    {
        $submitted = Article::whereNotNull('submitted_at')
            ->when($start, fn ($q) => $q->whereBetween('submitted_at', [$start, $end]))->count();

        $published = Article::where('current_stage', WF::PUBLISHED)->whereNotNull('published_at')
            ->when($start, fn ($q) => $q->whereBetween('published_at', [$start, $end]))->count();

        $clients = Article::whereNotNull('client_id')
            ->when($start, fn ($q) => $q->whereBetween('submitted_at', [$start, $end]))
            ->distinct()->count('client_id');

        $viralActive = ViralPackage::where('status', 'active')->count();
        $viralDelivered = ViralPackage::where('status', 'completed')
            ->when($start, fn ($q) => $q->whereBetween('completed_at', [$start, $end]))->count();

        $events = DB::table('stage_histories')
            ->when($start, fn ($q) => $q->whereBetween('changed_at', [$start, $end]))->count();

        if ($start) {
            $usersActive = (int) DB::table('stage_histories')->whereBetween('changed_at', [$start, $end])
                ->whereNotNull('changed_by')->distinct()->count('changed_by');
        } else {
            $reps = Article::whereNotNull('sales_rep_id')->distinct()->pluck('sales_rep_id');
            $writers = Article::whereNotNull('tech_writer_id')->distinct()->pluck('tech_writer_id');
            $usersActive = $reps->merge($writers)->unique()->count();
        }

        return [
            'submitted' => $submitted,
            'published' => $published,
            'clients' => $clients,
            'viral_active' => $viralActive,
            'viral_delivered' => $viralDelivered,
            'users_active' => $usersActive,
            'events' => $events,
        ];
    }

    private function articlesPerMonth()
    {
        return collect(range(11, 0))->map(function ($back) {
            $d = now()->subMonths($back);

            return [
                'label' => $d->format('M Y'),
                'ym' => $d->format('Y-m'),
                'submitted' => Article::whereYear('submitted_at', $d->year)->whereMonth('submitted_at', $d->month)->count(),
                'published' => Article::where('current_stage', WF::PUBLISHED)->whereYear('published_at', $d->year)->whereMonth('published_at', $d->month)->count(),
            ];
        })->values();
    }

    private function articlesPerWeek()
    {
        return collect(range(11, 0))->map(function ($back) {
            $ws = now()->startOfWeek()->subWeeks($back);
            $we = $ws->copy()->endOfWeek();

            return [
                'label' => 'W'.$ws->isoWeek().' '.$ws->format('M j'),
                'submitted' => Article::whereBetween('submitted_at', [$ws, $we])->count(),
                'published' => Article::where('current_stage', WF::PUBLISHED)->whereBetween('published_at', [$ws, $we])->count(),
            ];
        })->values();
    }

    private function perYear()
    {
        $years = collect(Article::whereNotNull('submitted_at')->selectRaw('distinct year(submitted_at) as y')->pluck('y'))
            ->merge(Article::whereNotNull('published_at')->selectRaw('distinct year(published_at) as y')->pluck('y'))
            ->filter()->unique()->sortDesc()->values();

        return $years->map(function ($y) {
            $sub = Article::whereYear('submitted_at', $y)->count();
            $pub = Article::where('current_stage', WF::PUBLISHED)->whereYear('published_at', $y)->count();

            return ['year' => (int) $y, 'submitted' => $sub, 'published' => $pub, 'rate' => $sub ? (int) round($pub / $sub * 100) : 0];
        })->values();
    }

    private function topClients()
    {
        return DB::table('articles')->join('contacts', 'contacts.id', '=', 'articles.client_id')
            ->whereNull('articles.deleted_at')
            ->select('contacts.contact_person as client', 'contacts.business_name as company',
                DB::raw('count(*) as articles'),
                DB::raw("sum(case when articles.current_stage = 'published' then 1 else 0 end) as published"))
            ->groupBy('contacts.id', 'contacts.contact_person', 'contacts.business_name')
            ->orderByDesc('articles')->limit(15)->get()
            ->map(fn ($r) => [
                'client' => $r->client ?: $r->company,
                'company' => $r->company,
                'articles' => (int) $r->articles,
                'published' => (int) $r->published,
            ])->values();
    }

    private function salesReps()
    {
        return DB::table('articles')->join('users', 'users.id', '=', 'articles.sales_rep_id')
            ->whereNull('articles.deleted_at')
            ->select('users.name', DB::raw('count(*) as submitted'),
                DB::raw("sum(case when articles.current_stage = 'published' then 1 else 0 end) as published"))
            ->groupBy('users.id', 'users.name')->orderByDesc('submitted')->get()
            ->map(fn ($r) => [
                'name' => $r->name,
                'submitted' => (int) $r->submitted,
                'published' => (int) $r->published,
                'rate' => $r->submitted ? (int) round($r->published / $r->submitted * 100) : 0,
            ])->values();
    }

    private function techWriters()
    {
        return DB::table('articles')->join('users', 'users.id', '=', 'articles.tech_writer_id')
            ->whereNull('articles.deleted_at')
            ->select('users.name', DB::raw('count(*) as assigned'),
                DB::raw("sum(case when articles.current_stage = 'published' then 1 else 0 end) as published"))
            ->groupBy('users.id', 'users.name')->orderByDesc('assigned')->get()
            ->map(fn ($r) => [
                'name' => $r->name,
                'assigned' => (int) $r->assigned,
                'published' => (int) $r->published,
                'rate' => $r->assigned ? (int) round($r->published / $r->assigned * 100) : 0,
            ])->values();
    }

    private function viralPerMonth()
    {
        return collect(range(11, 0))->map(function ($back) {
            $d = now()->subMonths($back);

            return [
                'label' => $d->format('M Y'),
                'created' => ViralPackage::whereYear('created_at', $d->year)->whereMonth('created_at', $d->month)->count(),
                'delivered' => ViralPackage::whereYear('completed_at', $d->year)->whereMonth('completed_at', $d->month)->count(),
            ];
        })->values();
    }

    private function stageTransitions(): array
    {
        $byTo = DB::table('stage_histories')->select('to_stage', DB::raw('count(*) as c'))->groupBy('to_stage')->pluck('c', 'to_stage');

        return [
            'assignments' => (int) ($byTo[WF::ASSIGNED] ?? 0),
            'corrections' => (int) ($byTo[WF::REVISIONS] ?? 0),
            'approvals' => (int) ($byTo[WF::APPROVED] ?? 0),
            'publications' => (int) ($byTo[WF::PUBLISHED] ?? 0),
            'total' => (int) $byTo->sum(),
        ];
    }

    private function monthOptions()
    {
        return collect(range(0, 11))->map(function ($back) {
            $d = now()->subMonths($back);

            return ['value' => $d->format('Y-m'), 'label' => $d->format('M Y')];
        })->values();
    }

    public function index(Request $request)
    {
        $month = $request->query('month');
        [$start, $end] = $this->range($month);

        return response()->json([
            'filter' => [
                'month' => $start ? $month : 'all',
                'label' => $start ? $start->format('F Y') : 'All time (lifetime)',
            ],
            'month_options' => $this->monthOptions(),
            'kpis' => $this->kpis($start, $end),
            'articles_per_month' => $this->articlesPerMonth(),
            'articles_per_week' => $this->articlesPerWeek(),
            'per_year' => $this->perYear(),
            'top_clients' => $this->topClients(),
            'sales_reps' => $this->salesReps(),
            'tech_writers' => $this->techWriters(),
            'viral_per_month' => $this->viralPerMonth(),
            'stage_transitions' => $this->stageTransitions(),
        ]);
    }

    /** Streamed CSV for a given section (?section=…). */
    public function export(Request $request)
    {
        $section = $request->query('section', 'clients');

        [$name, $header, $rows] = match ($section) {
            'monthly' => ['articles-per-month', ['Month', 'Submitted', 'Published'],
                $this->articlesPerMonth()->map(fn ($r) => [$r['label'], $r['submitted'], $r['published']])],
            'weekly' => ['articles-per-week', ['Week', 'Submitted', 'Published'],
                $this->articlesPerWeek()->map(fn ($r) => [$r['label'], $r['submitted'], $r['published']])],
            'yearly' => ['per-year', ['Year', 'Submitted', 'Published', 'Rate %'],
                $this->perYear()->map(fn ($r) => [$r['year'], $r['submitted'], $r['published'], $r['rate']])],
            'reps' => ['sales-reps', ['Rep', 'Submitted', 'Published', 'Success %'],
                $this->salesReps()->map(fn ($r) => [$r['name'], $r['submitted'], $r['published'], $r['rate']])],
            'writers' => ['tech-writers', ['Writer', 'Assigned', 'Published', 'Done %'],
                $this->techWriters()->map(fn ($r) => [$r['name'], $r['assigned'], $r['published'], $r['rate']])],
            'viral' => ['viral-per-month', ['Month', 'Created', 'Delivered'],
                $this->viralPerMonth()->map(fn ($r) => [$r['label'], $r['created'], $r['delivered']])],
            default => ['top-clients', ['Client', 'Company', 'Articles', 'Published'],
                $this->topClients()->map(fn ($r) => [$r['client'], $r['company'], $r['articles'], $r['published']])],
        };

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $name.'-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }
}
