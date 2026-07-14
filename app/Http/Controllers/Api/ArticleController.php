<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use App\Services\ArticleWorkflowService;
use App\Support\ArticleWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ArticleController extends Controller
{
    public function __construct(private ArticleWorkflowService $workflow) {}

    public function index(Request $request)
    {
        $q = Article::query()->with(['client', 'salesRep', 'writer']);

        // Contributors (sales reps / writers) only see their own; coordinators see all.
        if (! $request->user()->can('content.articles.assign')) {
            $uid = $request->user()->id;
            $q->where(fn ($w) => $w->where('sales_rep_id', $uid)->orWhere('tech_writer_id', $uid));
        }

        if ($request->filled('stage')) {
            $q->where('current_stage', $request->input('stage'));
        }
        if ($request->filled('stages')) {
            $q->whereIn('current_stage', array_filter(explode(',', (string) $request->input('stages'))));
        }
        if ($request->boolean('writer_mine')) {
            $q->where('tech_writer_id', $request->user()->id);
        }
        if ($request->filled('priority')) {
            $q->where('priority', $request->input('priority'));
        }
        if ($request->boolean('mine')) {
            $uid = $request->user()->id;
            $q->where(fn ($w) => $w->where('tech_writer_id', $uid)->orWhere('sales_rep_id', $uid));
        }
        if ($search = trim((string) $request->input('search'))) {
            $q->where(fn ($w) => $w->where('title', 'like', "%{$search}%")->orWhere('article_code', 'like', "%{$search}%"));
        }

        return ArticleResource::collection($q->latest()->paginate($request->integer('per_page', 30)));
    }

    public function stats(Request $request)
    {
        // Match the list scoping — contributors see their own numbers.
        $base = fn () => Article::query()->when(
            ! $request->user()->can('content.articles.assign'),
            fn ($q) => $q->where(fn ($w) => $w->where('sales_rep_id', $request->user()->id)->orWhere('tech_writer_id', $request->user()->id)),
        );
        $byStage = (clone $base())->select('current_stage', DB::raw('count(*) as c'))->groupBy('current_stage')->pluck('c', 'current_stage');

        return response()->json([
            'total' => (clone $base())->count(),
            'in_progress' => (int) ($byStage['in_progress'] ?? 0) + (int) ($byStage['assigned'] ?? 0),
            'awaiting_review' => (int) ($byStage['client_approval'] ?? 0),
            'published' => (int) ($byStage['published'] ?? 0),
            'by_stage' => $byStage,
        ]);
    }

    public function catalog()
    {
        return response()->json([
            'stages' => ArticleWorkflow::catalog(),
            'priorities' => ['low', 'medium', 'high'],
        ]);
    }

    /** Active users who can write articles — for the assign picker. */
    public function writers()
    {
        return response()->json([
            'data' => \App\Models\User::permission('content.articles.write')->where('is_active', true)->get(['id', 'name']),
        ]);
    }

    public function show(Article $article)
    {
        return new ArticleResource($article->load([
            'client', 'salesRep', 'writer', 'history.changer', 'comments.user', 'assets',
        ]));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'client_id' => ['nullable', 'exists:contacts,id'],
            'tech_writer_id' => ['nullable', 'exists:users,id'],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'deadline' => ['nullable', 'date'],
            'word_count_target' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'source' => ['nullable', 'file', 'max:20480'],
        ]);

        $article = $this->workflow->submit($data, $request->user(), $request->file('source'));

        return (new ArticleResource($article->load(['client', 'salesRep'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, Article $article)
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:190'],
            'client_id' => ['nullable', 'exists:contacts,id'],
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high'])],
            'deadline' => ['nullable', 'date'],
            'word_count_target' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);
        $article->update($data);

        return new ArticleResource($article->load(['client', 'salesRep', 'writer']));
    }

    // --- Workflow transitions ---

    public function assign(Request $request, Article $article)
    {
        $data = $request->validate(['writer_id' => ['required', 'exists:users,id']]);

        return $this->fresh($this->workflow->assign($article, $data['writer_id'], $request->user()));
    }

    public function selfAssign(Request $request, Article $article)
    {
        return $this->fresh($this->workflow->selfAssign($article, $request->user()));
    }

    public function start(Request $request, Article $article)
    {
        return $this->fresh($this->workflow->start($article, $request->user()));
    }

    public function submitReview(Request $request, Article $article)
    {
        $request->validate(['rewrite' => ['nullable', 'file', 'max:20480']]);

        return $this->fresh($this->workflow->submitForReview($article, $request->user(), $request->file('rewrite')));
    }

    public function requestRevision(Request $request, Article $article)
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        return $this->fresh($this->workflow->requestRevision($article, $request->user(), $data['notes'] ?? null));
    }

    public function revokeRevision(Request $request, Article $article)
    {
        return $this->fresh($this->workflow->revokeRevision($article, $request->user()));
    }

    public function clientApproved(Request $request, Article $article)
    {
        return $this->fresh($this->workflow->clientApproved($article, $request->user()));
    }

    public function publish(Request $request, Article $article)
    {
        $data = $request->validate(['published_url' => ['nullable', 'url', 'max:1000']]);

        return $this->fresh($this->workflow->publish($article, $request->user(), $data['published_url'] ?? null));
    }

    public function comment(Request $request, Article $article)
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $article->comments()->create(['user_id' => $request->user()->id, 'body' => $data['body']]);

        return new ArticleResource($article->load(['client', 'salesRep', 'writer', 'history.changer', 'comments.user', 'assets']));
    }

    public function destroy(Article $article)
    {
        $article->delete();

        return response()->json(['message' => 'Article deleted.']);
    }

    private function fresh(Article $article): ArticleResource
    {
        return new ArticleResource($article->fresh()->load(['client', 'salesRep', 'writer', 'history.changer', 'comments.user', 'assets']));
    }
}
