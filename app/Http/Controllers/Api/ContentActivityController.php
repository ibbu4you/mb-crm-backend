<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StageHistory;
use App\Support\ArticleWorkflow as WF;
use Illuminate\Http\Request;

class ContentActivityController extends Controller
{
    public function __invoke(Request $request)
    {
        $items = StageHistory::with(['changer'])
            ->join('articles', 'articles.id', '=', 'stage_histories.article_id')
            ->select('stage_histories.*', 'articles.article_code', 'articles.title')
            ->latest('stage_histories.changed_at')
            ->limit($request->integer('limit', 40))
            ->get()
            ->map(fn (StageHistory $h) => [
                'id' => $h->id,
                'article_code' => $h->article_code,
                'title' => $h->title,
                'from' => $h->from_stage ? WF::label($h->from_stage) : null,
                'to' => WF::label($h->to_stage),
                'to_stage' => $h->to_stage,
                'notes' => $h->notes,
                'by' => $h->changer?->name ?? 'system',
                'at' => $h->changed_at,
            ]);

        return response()->json(['data' => $items]);
    }
}
