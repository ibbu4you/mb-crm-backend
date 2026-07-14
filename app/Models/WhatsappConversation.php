<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappConversation extends Model
{
    protected $fillable = ['phone', 'contact_name', 'state', 'data', 'lead_id', 'last_activity_at', 'agent_read_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'last_activity_at' => 'datetime', 'agent_read_at' => 'datetime'];
    }
}
