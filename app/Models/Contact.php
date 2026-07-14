<?php

namespace App\Models;

use App\Support\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'business_name', 'contact_person', 'email', 'phone', 'phone_normalized',
        'industry', 'city', 'address', 'source', 'owner_id', 'created_by', 'notes', 'meta',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (Contact $c) {
            $c->phone_normalized = $c->phone ? preg_replace('/\D+/', '', $c->phone) : null;
        });
    }

    public static function normalizePhone(?string $phone): ?string
    {
        return $phone ? preg_replace('/\D+/', '', $phone) : null;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class, 'client_id');
    }

    public function viralPackages(): HasMany
    {
        return $this->hasMany(ViralPackage::class, 'contact_id');
    }
}
