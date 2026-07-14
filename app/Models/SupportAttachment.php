<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SupportAttachment extends Model
{
    protected $fillable = ['attachable_id', 'attachable_type', 'path', 'original_name', 'size', 'mime', 'uploaded_by'];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getUrlAttribute(): string
    {
        return asset('storage/'.$this->path);
    }
}
