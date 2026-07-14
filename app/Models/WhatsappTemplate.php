<?php

namespace App\Models;

use App\Support\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappTemplate extends Model
{
    use Auditable;

    protected $fillable = ['name', 'category', 'language', 'header', 'body', 'footer', 'buttons', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['buttons' => 'array'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Number of {{n}} placeholders in the body. */
    public function getVariableCountAttribute(): int
    {
        preg_match_all('/\{\{\s*\d+\s*\}\}/', (string) $this->body, $m);

        return count(array_unique($m[0]));
    }
}
