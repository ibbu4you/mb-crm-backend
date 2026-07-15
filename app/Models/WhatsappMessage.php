<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappMessage extends Model
{
    protected $fillable = ['phone', 'direction', 'body', 'payload', 'status',
        'media_type', 'media_url', 'media_name'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
