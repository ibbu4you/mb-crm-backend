<?php

namespace App\Services;

use App\Models\Article;
use App\Models\User;
use App\Notifications\ArticleEvent;
use App\Support\ArticleWorkflow as WF;
use Illuminate\Http\UploadedFile;

class ArticleWorkflowService
{
    /** Sales submits a brief -> creates the article in Inbox. */
    public function submit(array $data, User $actor, ?UploadedFile $source = null): Article
    {
        $writerId = $data['tech_writer_id'] ?? null;

        $article = Article::create([
            'article_code' => Article::nextCode(),
            'title' => $data['title'],
            'client_id' => $data['client_id'] ?? null,
            'sales_rep_id' => $actor->id,
            'tech_writer_id' => $writerId,
            'current_stage' => $writerId ? WF::ASSIGNED : WF::INBOX,
            'priority' => $data['priority'] ?? 'medium',
            'deadline' => $data['deadline'] ?? null,
            'word_count_target' => $data['word_count_target'] ?? null,
            'notes' => $data['notes'] ?? null,
            'source_file_path' => $source?->store('articles/source', 'public'),
            'submitted_at' => now(),
            'stage_entered_at' => now(),
        ]);

        $article->history()->create([
            'from_stage' => null, 'to_stage' => WF::INBOX,
            'changed_by' => $actor->id, 'changed_at' => now(),
        ]);

        // Pre-assigned to a content-team writer on submission.
        if ($writerId) {
            $article->history()->create([
                'from_stage' => WF::INBOX, 'to_stage' => WF::ASSIGNED,
                'changed_by' => $actor->id, 'changed_at' => now(),
                'notes' => 'Assigned to writer on submission',
            ]);
            $this->notify(User::find($writerId), $article, 'assigned', "You've been assigned {$article->article_code}: {$article->title}");
        }

        return $article;
    }

    /** Admin assigns a writer. */
    public function assign(Article $article, int $writerId, User $actor): Article
    {
        $this->ensureStage($article, [WF::INBOX, WF::ASSIGNED]);
        $article->tech_writer_id = $writerId;
        $this->transition($article, WF::ASSIGNED, $actor);
        $this->notify(User::find($writerId), $article, 'assigned', "You've been assigned {$article->article_code}: {$article->title}");

        return $article;
    }

    /** A writer picks up an unassigned article. */
    public function selfAssign(Article $article, User $actor): Article
    {
        $this->ensureStage($article, [WF::INBOX]);
        $article->tech_writer_id = $actor->id;
        $this->transition($article, WF::ASSIGNED, $actor);

        return $article;
    }

    public function start(Article $article, User $actor): Article
    {
        $this->ensureWriter($article, $actor);
        $this->ensureStage($article, [WF::ASSIGNED, WF::REVISIONS]);
        $this->transition($article, WF::IN_PROGRESS, $actor);

        return $article;
    }

    /** Writer submits the rewrite -> straight to Sales review (skips internal_review). */
    public function submitForReview(Article $article, User $actor, ?UploadedFile $rewrite = null): Article
    {
        $this->ensureWriter($article, $actor);
        $this->ensureStage($article, [WF::IN_PROGRESS]);
        if ($rewrite) {
            $article->current_file_path = $rewrite->store('articles/rewrites', 'public');
        }
        $this->transition($article, WF::CLIENT_APPROVAL, $actor);
        $this->notify($article->salesRep, $article, 'submitted', "{$article->article_code} is ready for your review.");

        return $article;
    }

    public function requestRevision(Article $article, User $actor, ?string $notes = null): Article
    {
        $this->ensureStage($article, [WF::CLIENT_APPROVAL]);
        $this->transition($article, WF::REVISIONS, $actor, $notes);
        $this->notify($article->writer, $article, 'revision', "Correction requested on {$article->article_code}.");

        return $article;
    }

    public function revokeRevision(Article $article, User $actor): Article
    {
        $this->ensureStage($article, [WF::REVISIONS]);
        $this->transition($article, WF::CLIENT_APPROVAL, $actor);

        return $article;
    }

    public function clientApproved(Article $article, User $actor): Article
    {
        $this->ensureStage($article, [WF::CLIENT_APPROVAL]);
        $this->transition($article, WF::APPROVED, $actor);
        $this->notify($article->writer, $article, 'approved', "{$article->article_code} was approved.");

        return $article;
    }

    public function publish(Article $article, User $actor, ?string $url = null): Article
    {
        $this->ensureStage($article, [WF::APPROVED, WF::CLIENT_APPROVAL]);
        $article->published_url = $url;
        $article->published_at = now();
        $this->transition($article, WF::PUBLISHED, $actor);
        $this->notify($article->salesRep, $article, 'published', "{$article->article_code} has been published.");

        return $article;
    }

    // --- helpers ---

    private function transition(Article $article, string $to, User $actor, ?string $notes = null): void
    {
        $from = $article->current_stage;
        $article->current_stage = $to;
        $article->stage_entered_at = now();
        $article->save();

        $article->history()->create([
            'from_stage' => $from, 'to_stage' => $to,
            'changed_by' => $actor->id, 'notes' => $notes, 'changed_at' => now(),
        ]);
    }

    private function ensureStage(Article $article, array $allowed): void
    {
        abort_unless(in_array($article->current_stage, $allowed, true), 422, 'That action is not allowed from the current stage ('.WF::label($article->current_stage).').');
    }

    private function ensureWriter(Article $article, User $actor): void
    {
        abort_unless(
            $article->tech_writer_id === $actor->id || $actor->can('content.articles.assign'),
            403,
            'Only the assigned writer can do this.'
        );
    }

    private function notify(?User $user, Article $article, string $event, string $message): void
    {
        $user?->notify(new ArticleEvent($article, $event, $message));
    }
}
