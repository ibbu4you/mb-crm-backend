<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortfolioImage extends Model
{
    protected $fillable = ['portfolio_item_id', 'image_path', 'sort_order'];

    public function getUrlAttribute(): string
    {
        return asset('storage/'.$this->image_path);
    }
}
