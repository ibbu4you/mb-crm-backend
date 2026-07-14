<?php

namespace App\Models;

use App\Support\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PortfolioItem extends Model
{
    use Auditable;

    protected $fillable = ['type', 'title', 'url', 'image_path', 'description', 'credentials', 'sort_order', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['credentials' => 'array', 'is_active' => 'boolean'];
    }

    public function images(): HasMany
    {
        return $this->hasMany(PortfolioImage::class)->orderBy('sort_order');
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? asset('storage/'.$this->image_path) : null;
    }
}
