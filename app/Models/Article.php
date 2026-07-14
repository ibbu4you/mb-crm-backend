<?php

namespace App\Models;

use App\Support\ArticleWorkflow;
use App\Support\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Article extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'article_code', 'title', 'client_id', 'sales_rep_id', 'tech_writer_id',
        'current_stage', 'priority', 'deadline', 'word_count_target', 'source_file_path',
        'current_file_path', 'published_url', 'published_at', 'submitted_at', 'stage_entered_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'deadline' => 'date',
            'published_at' => 'datetime',
            'submitted_at' => 'datetime',
            'stage_entered_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'client_id');
    }

    public function salesRep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_rep_id');
    }

    public function writer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tech_writer_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(StageHistory::class)->latest('changed_at');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ArticleComment::class)->latest();
    }

    public function assets(): HasMany
    {
        return $this->hasMany(ArticleAsset::class)->latest();
    }

    public function getStageLabelAttribute(): string
    {
        return ArticleWorkflow::label($this->current_stage);
    }

    public static function nextCode(): string
    {
        $max = (int) (static::withTrashed()->max('id') ?? 0);

        return 'ART-'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}
