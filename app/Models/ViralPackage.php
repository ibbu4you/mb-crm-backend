<?php

namespace App\Models;

use App\Support\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ViralPackage extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = ['code', 'contact_id', 'sales_rep_id', 'tech_team_id', 'title', 'status', 'completed_at', 'notes'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function salesRep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_rep_id');
    }

    public function techTeam(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tech_team_id');
    }

    public function deliverables(): HasMany
    {
        return $this->hasMany(ViralDeliverable::class)->orderBy('kind')->orderBy('slot_number');
    }

    public static function nextCode(): string
    {
        $max = (int) (static::withTrashed()->max('id') ?? 0);

        return 'VP-'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}
