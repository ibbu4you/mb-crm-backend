<?php

namespace App\Notifications;

use App\Models\Article;
use Illuminate\Notifications\Notification;

class ArticleEvent extends Notification
{
    public function __construct(
        public Article $article,
        public string $event,
        public string $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'article',
            'event' => $this->event,
            'article_id' => $this->article->id,
            'code' => $this->article->article_code,
            'title' => $this->article->title,
            'message' => $this->message,
            'url' => '/articles',
            'icon' => 'article',
        ];
    }
}
