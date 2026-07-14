<?php

namespace App\Http\Resources;

use App\Support\ArticleWorkflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArticleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'article_code' => $this->article_code,
            'title' => $this->title,
            'current_stage' => $this->current_stage,
            'stage_label' => ArticleWorkflow::label($this->current_stage),
            'priority' => $this->priority,
            'deadline' => $this->deadline?->toDateString(),
            'word_count_target' => $this->word_count_target,
            'notes' => $this->notes,
            'published_url' => $this->published_url,
            'published_at' => $this->published_at,
            'submitted_at' => $this->submitted_at,
            'stage_entered_at' => $this->stage_entered_at,
            'source_file_url' => $this->source_file_path ? asset('storage/'.$this->source_file_path) : null,
            'current_file_url' => $this->current_file_path ? asset('storage/'.$this->current_file_path) : null,
            'client' => $this->whenLoaded('client', fn () => $this->client?->only('id', 'business_name')),
            'sales_rep' => $this->whenLoaded('salesRep', fn () => $this->salesRep?->only('id', 'name')),
            'writer' => $this->whenLoaded('writer', fn () => $this->writer?->only('id', 'name')),
            'history' => $this->whenLoaded('history', fn () => $this->history->map(fn ($h) => [
                'id' => $h->id,
                'from_stage' => $h->from_stage,
                'to_stage' => $h->to_stage,
                'to_label' => ArticleWorkflow::label($h->to_stage),
                'notes' => $h->notes,
                'changed_by' => $h->changer?->only('id', 'name'),
                'changed_at' => $h->changed_at,
            ])),
            'comments' => $this->whenLoaded('comments', fn () => $this->comments->map(fn ($c) => [
                'id' => $c->id, 'body' => $c->body, 'user' => $c->user?->only('id', 'name'), 'created_at' => $c->created_at,
            ])),
            'assets' => $this->whenLoaded('assets', fn () => $this->assets->map(fn ($a) => [
                'id' => $a->id, 'type' => $a->type, 'name' => $a->name, 'url' => $a->type === 'link' ? $a->url : $a->file_url, 'created_at' => $a->created_at,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
