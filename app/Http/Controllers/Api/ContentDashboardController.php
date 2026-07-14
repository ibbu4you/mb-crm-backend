<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Setting;
use App\Models\ViralDeliverable;
use App\Models\ViralPackage;
use App\Support\ArticleWorkflow as WF;
use App\Support\ViralWorkflow as VW;
use Illuminate\Support\Facades\DB;

class ContentDashboardController extends Controller
{
    public function __invoke()
    {
        $stuckDays = (int) Setting::get('content.stuck_threshold_days', 3);

        $byStage = Article::select('current_stage', DB::raw('count(*) as c'))->groupBy('current_stage')->pluck('c', 'current_stage');
        $activeStages = collect(WF::all())->reject(fn ($s) => $s === WF::PUBLISHED);

        $articles = [
            'total_active' => (int) $activeStages->sum(fn ($s) => (int) ($byStage[$s] ?? 0)),
            'due_this_week' => Article::whereNotNull('deadline')->whereBetween('deadline', [now()->startOfWeek(), now()->endOfWeek()])
                ->where('current_stage', '!=', WF::PUBLISHED)->count(),
            'stuck' => Article::where('current_stage', '!=', WF::PUBLISHED)
                ->where('stage_entered_at', '<', now()->subDays($stuckDays))->count(),
            'published_this_month' => Article::where('current_stage', WF::PUBLISHED)
                ->whereMonth('published_at', now()->month)->whereYear('published_at', now()->year)->count(),
        ];

        $pipeline = collect(WF::all())->map(fn ($s) => [
            'stage' => $s, 'label' => WF::label($s), 'color' => WF::META[$s]['color'], 'count' => (int) ($byStage[$s] ?? 0),
        ])->values();

        // Viral summary
        $activeCampaigns = ViralPackage::where('status', 'active')->with(['contact', 'techTeam', 'deliverables'])
            ->latest()->limit(6)->get()->map(function (ViralPackage $p) {
                $total = $p->deliverables->count();
                $approved = $p->deliverables->where('stage', VW::APPROVED)->count();

                return [
                    'id' => $p->id, 'code' => $p->code,
                    'name' => $p->contact?->business_name,
                    'team' => $p->techTeam?->name ?? 'Content Team',
                    'approved' => $approved, 'total' => $total,
                    'pct' => $total ? round($approved / $total * 100) : 0,
                ];
            });

        $viral = [
            'active' => ViralPackage::where('status', 'active')->count(),
            'in_review' => ViralDeliverable::where('stage', VW::REVIEW)->count(),
            'delivered_this_month' => ViralPackage::where('status', 'completed')
                ->whereMonth('completed_at', now()->month)->whereYear('completed_at', now()->year)->count(),
            'active_campaigns' => $activeCampaigns,
        ];

        return response()->json(['articles' => $articles, 'pipeline' => $pipeline, 'viral' => $viral]);
    }
}
