<?php

namespace App\Models;

use App\Support\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupportTicket extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = ['code', 'subject', 'description', 'priority', 'status', 'reporter_id', 'assignee_id', 'last_activity_at', 'resolved_at', 'closed_at'];

    protected function casts(): array
    {
        return ['last_activity_at' => 'datetime', 'resolved_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(SupportTicketReply::class, 'ticket_id')->orderBy('created_at');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(SupportAttachment::class, 'attachable');
    }

    public static function nextCode(): string
    {
        $max = (int) (static::withTrashed()->max('id') ?? 0);

        return 'TKT-'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}
