<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortfolioImage extends Model
{
    protected $fillable = ['portfolio_item_id', 'image_path', 'sort_order'];

    public function getUrlAttribute(): string
    {
        return preg_match('#^https?://#i', $this->image_path)
            ? $this->image_path
            : asset('storage/'.$this->image_path);
    }
}
