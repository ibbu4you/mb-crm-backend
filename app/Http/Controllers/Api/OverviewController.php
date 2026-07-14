<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Attendance;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\SupportTicket;
use App\Models\Task;
use App\Models\User;
use App\Models\ViralPackage;
use App\Models\Visit;
use App\Models\WhatsappCampaign;
use App\Models\WhatsappGroupMember;
use App\Models\WhatsappMessage;
use App\Support\ArticleWorkflow as WF;
use App\Support\Pipeline;
use App\Support\SupportDesk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The executive overview dashboard. Aggregates headline metrics across every
 * department. Each section is only computed (and returned) when the requesting
 * user holds the relevant permission — so the dashboard automatically adapts to
 * the viewer's role without leaking data they can't otherwise see.
 */
class OverviewController extends Controller
{
    public function __invoke(Request $request)
    {
        $u = $request->user();
        $out = ['days' => $this->days()];

        if ($u->canAny(['employees.view', 'employees.manage'])) {
            $out['people'] = $this->people();
        }
        if ($u->canAny(['leads.view', 'leads.view.all', 'leads.manage'])) {
            $out['leads'] = $this->leads($u);
        }
        if ($u->canAny(['sales.reports.view', 'sales.reports.view.all', 'sales.visits.log'])) {
            $out['sales'] = $this->sales($u);
        }
        if ($u->can('content.articles.view')) {
            $out['content'] = $this->content($u);
        }
        if ($u->canAny(['support.view', 'support.handle'])) {
            $out['support'] = $this->support();
        }
        if ($u->canAny(['invoicing.view', 'invoicing.manage', 'invoicing.reports.view'])) {
            $out['invoicing'] = $this->invoicing();
        }
        if ($u->can('tasks.view')) {
            $out['tasks'] = $this->tasks($u);
        }
        if ($u->canAny(['hrms.attendance.manage', 'hrms.view'])) {
            $out['attendance'] = $this->attendance();
        }
        if ($u->canAny(['whatsapp.view', 'whatsapp.send', 'whatsapp.manage'])) {
            $out['whatsapp'] = $this->whatsapp();
        }

        return response()->json($out);
    }

    /** Last 14 day labels (MM-DD) for the trend charts. */
    private function days(): array
    {
        return collect(range(13, 0))->map(fn ($i) => now()->subDays($i)->format('m-d'))->all();
    }

    private function daily($query, string $col): array
    {
        $since = now()->subDays(13)->startOfDay();
        $rows = (clone $query)->where($col, '>=', $col === 'visit_date' ? $since->toDateString() : $since)
            ->selectRaw("DATE($col) d, count(*) c")->groupBy('d')->pluck('c', 'd');

        return collect(range(13, 0))->map(fn ($i) => (int) ($rows[now()->subDays($i)->toDateString()] ?? 0))->all();
    }

    private function people(): array
    {
        $total = User::count();
        $active = User::where('is_active', true)->count();
        $byRole = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->select('roles.name', DB::raw('count(*) as c'))
            ->groupBy('roles.name')->orderByDesc('c')->get()
            ->map(fn ($r) => ['label' => $r->name, 'count' => (int) $r->c])->all();

        return ['total' => $total, 'active' => $active, 'inactive' => $total - $active, 'by_role' => $byRole];
    }

    private function leads(User $u): array
    {
        $all = $u->can('leads.view.all');
        $base = fn () => Lead::query()->when(! $all, fn ($q) => $q->where('owner_id', $u->id));

        $counts = $base()->select('pipeline_stage', DB::raw('count(*) as c'))->groupBy('pipeline_stage')->pluck('c', 'pipeline_stage');
        $stages = collect(Pipeline::all())->map(fn ($s) => [
            'label' => Pipeline::label($s), 'color' => Pipeline::META[$s]['color'], 'count' => (int) ($counts[$s] ?? 0),
        ])->values()->all();
        $won = (int) ($counts['won'] ?? 0);
        $lost = (int) ($counts['lost'] ?? 0);
        $bySource = $base()->select('source', DB::raw('count(*) as c'))->groupBy('source')->orderByDesc('c')->get()
            ->map(fn ($r) => ['label' => $r->source ?: 'other', 'count' => (int) $r->c])->all();

        return [
            'total' => $base()->count(),
            'open' => (int) collect(Pipeline::STAGES)->sum(fn ($s) => (int) ($counts[$s] ?? 0)),
            'won' => $won,
            'lost' => $lost,
            'new_this_month' => $base()->where('created_at', '>=', now()->startOfMonth())->count(),
            'conversion' => ($won + $lost) ? (int) round($won / ($won + $lost) * 100) : 0,
            'revenue_potential' => (float) $base()->whereIn('pipeline_stage', Pipeline::STAGES)->sum('revenue_potential'),
            'by_stage' => $stages,
            'by_source' => $bySource,
            'series' => $this->daily($base(), 'created_at'),
        ];
    }

    private function sales(User $u): array
    {
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();
        $all = $u->can('sales.reports.view.all');
        $visits = fn () => Visit::query()->when(! $all, fn ($q) => $q->where('user_id', $u->id));
        $deals = fn () => Deal::query()->when(! $all, fn ($q) => $q->where('user_id', $u->id));

        return [
            'visits_month' => $visits()->whereBetween('visit_date', [$start->toDateString(), $end->toDateString()])->count(),
            'interested_month' => $visits()->whereBetween('visit_date', [$start->toDateString(), $end->toDateString()])->where('interested', true)->count(),
            'deals_won_month' => $deals()->whereBetween('closed_at', [$start, $end])->where('outcome', 'won')->count(),
            'revenue_month' => (float) $deals()->whereBetween('closed_at', [$start, $end])->where('outcome', 'won')->sum('actual_revenue'),
            'visits_series' => $this->daily($visits(), 'visit_date'),
        ];
    }

    private function content($u): array
    {
        $byStage = Article::select('current_stage', DB::raw('count(*) as c'))->groupBy('current_stage')->pluck('c', 'current_stage');
        $stages = collect(WF::all())->map(fn ($s) => [
            'label' => WF::label($s), 'color' => WF::META[$s]['color'], 'count' => (int) ($byStage[$s] ?? 0),
        ])->values()->all();

        $reviewStages = ['internal_review', 'client_approval'];
        $activeWriter = ['assigned', 'in_progress', 'revisions'];
        $meta = fn ($s) => ['label' => WF::label($s), 'color' => WF::META[$s]['color'] ?? '#64748b'];

        // The signed-in writer's own queue + recent completions.
        $myQueue = Article::with('client')->where('tech_writer_id', $u->id)
            ->whereIn('current_stage', $activeWriter)->orderByRaw('deadline is null, deadline asc')->limit(8)->get()
            ->map(fn ($a) => array_merge($meta($a->current_stage), [
                'code' => $a->article_code, 'title' => $a->title, 'client' => $a->client?->business_name,
                'stage' => $a->current_stage, 'deadline' => $a->deadline?->toDateString(),
            ]))->values()->all();

        $recent = Article::with('client')->where('tech_writer_id', $u->id)
            ->whereIn('current_stage', ['approved', 'published'])->latest('updated_at')->limit(6)->get()
            ->map(fn ($a) => array_merge($meta($a->current_stage), [
                'code' => $a->article_code, 'title' => $a->title, 'client' => $a->client?->business_name,
            ]))->values()->all();

        $avg = Article::where('tech_writer_id', $u->id)->whereNotNull('published_at')->whereNotNull('submitted_at')->get()
            ->map(fn ($a) => $a->submitted_at->diffInDays($a->published_at))->avg();

        // Viral deliverables assigned to this writer.
        $myDeliv = fn () => \App\Models\ViralDeliverable::where('assigned_to', $u->id);
        $myPkgIds = (clone $myDeliv())->distinct()->pluck('viral_package_id');
        $campaigns = ViralPackage::whereIn('id', $myPkgIds)->where('status', 'active')->with(['contact', 'deliverables'])->latest()->limit(4)->get()
            ->map(function ($p) {
                $total = $p->deliverables->count();
                $approved = $p->deliverables->where('stage', 'approved')->count();
                return ['id' => $p->id, 'name' => $p->contact?->business_name ?? $p->title, 'approved' => $approved,
                    'total' => $total, 'pct' => $total ? (int) round($approved / $total * 100) : 0];
            })->values()->all();

        return [
            'active_articles' => (int) collect(WF::all())->reject(fn ($s) => $s === WF::PUBLISHED)->sum(fn ($s) => (int) ($byStage[$s] ?? 0)),
            'published_this_month' => Article::where('current_stage', WF::PUBLISHED)->whereMonth('published_at', now()->month)->whereYear('published_at', now()->year)->count(),
            'due_this_week' => Article::whereNotNull('deadline')->whereBetween('deadline', [today(), today()->copy()->addDays(7)])->where('current_stage', '!=', WF::PUBLISHED)->count(),
            'awaiting_review' => (int) collect($reviewStages)->sum(fn ($s) => (int) ($byStage[$s] ?? 0)),
            'my_active' => Article::where('tech_writer_id', $u->id)->whereIn('current_stage', $activeWriter)->count(),
            'completed_this_month' => Article::where('tech_writer_id', $u->id)->where('current_stage', WF::PUBLISHED)
                ->whereMonth('published_at', now()->month)->whereYear('published_at', now()->year)->count(),
            'avg_days' => $avg !== null ? round((float) $avg, 1) : null,
            'viral_active' => ViralPackage::where('status', 'active')->count(),
            'by_stage' => $stages,
            'my_queue' => $myQueue,
            'recently_completed' => $recent,
            'viral' => [
                'active' => (clone $myDeliv())->whereHas('package', fn ($q) => $q->where('status', 'active'))->distinct('viral_package_id')->count('viral_package_id'),
                'to_work_on' => (clone $myDeliv())->whereIn('stage', ['pending', 'in_progress'])->count(),
                'in_review' => (clone $myDeliv())->where('stage', 'review')->count(),
                'campaigns' => $campaigns,
            ],
        ];
    }

    private function support(): array
    {
        $raw = SupportTicket::select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status');
        $byStatus = collect(SupportDesk::STATUSES)->map(fn ($m, $k) => [
            'label' => $m['label'], 'color' => $m['color'], 'count' => (int) ($raw[$k] ?? 0),
        ])->values()->all();

        return [
            'open' => (int) ($raw['open'] ?? 0),
            'in_progress' => (int) ($raw['in_progress'] ?? 0),
            'unassigned' => SupportTicket::whereNull('assignee_id')->whereIn('status', ['open', 'in_progress'])->count(),
            'resolved_this_month' => SupportTicket::where('status', 'resolved')->where('resolved_at', '>=', now()->startOfMonth())->count(),
            'by_status' => $byStatus,
        ];
    }

    private function invoicing(): array
    {
        $invoiced = (float) Invoice::where('status', '!=', 'void')->sum('total');
        $paid = (float) Invoice::sum('amount_paid');
        $overdue = Invoice::whereIn('status', ['sent', 'partial'])->whereDate('due_date', '<', today());
        $byStatus = Invoice::select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status');

        $collected = collect(range(5, 0))->map(function ($i) {
            $m = now()->subMonths($i);

            return [
                'label' => $m->format('M'),
                'value' => (float) Payment::whereYear('paid_on', $m->year)->whereMonth('paid_on', $m->month)->sum('amount'),
            ];
        })->all();

        return [
            'invoiced' => round($invoiced, 2),
            'paid' => round($paid, 2),
            'outstanding' => round($invoiced - $paid, 2),
            'overdue_count' => (clone $overdue)->count(),
            'overdue_amount' => round((float) (clone $overdue)->sum(DB::raw('total - amount_paid')), 2),
            'collected_this_month' => (float) Payment::where('paid_on', '>=', now()->startOfMonth())->sum('amount'),
            'by_status' => $byStatus,
            'collected_series' => $collected,
        ];
    }

    private function tasks(User $u): array
    {
        $mine = fn () => Task::where('assignee_id', $u->id);

        return [
            'my_open' => $mine()->where('status', '!=', 'done')->count(),
            'my_overdue' => $mine()->where('status', '!=', 'done')->whereDate('due_date', '<', today())->count(),
            'done_this_week' => $mine()->where('status', 'done')->where('completed_at', '>=', now()->startOfWeek())->count(),
            'org_open' => Task::where('status', '!=', 'done')->count(),
        ];
    }

    private function attendance(): array
    {
        $today = today()->toDateString();

        return [
            'present_today' => Attendance::where('date', $today)->whereNotNull('check_in_at')->count(),
            'on_site_today' => Attendance::where('date', $today)->where('on_site', true)->count(),
            'late_today' => Attendance::where('date', $today)->where('status', 'late')->count(),
            'employees' => User::where('is_active', true)->count(),
        ];
    }

    private function whatsapp(): array
    {
        return [
            'sent' => WhatsappMessage::where('direction', 'out')->count(),
            'received' => WhatsappMessage::where('direction', 'in')->count(),
            'campaigns' => WhatsappCampaign::count(),
            'audience' => (int) WhatsappGroupMember::distinct()->count('phone'),
            'sent_series' => $this->daily(WhatsappMessage::where('direction', 'out'), 'created_at'),
            'received_series' => $this->daily(WhatsappMessage::where('direction', 'in'), 'created_at'),
        ];
    }
}
