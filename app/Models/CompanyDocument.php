<?php

namespace App\Models;

use App\Support\Auditable;
use Illuminate\Database\Eloquent\Model;

class CompanyDocument extends Model
{
    use Auditable;

    protected $fillable = ['title', 'category', 'file_path', 'original_name', 'size', 'is_active', 'sort_order', 'uploaded_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getFileUrlAttribute(): string
    {
        return asset('storage/'.$this->file_path);
    }
}
