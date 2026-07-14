<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappGroupMember extends Model
{
    protected $fillable = ['group_id', 'contact_id', 'name', 'phone'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(WhatsappGroup::class, 'group_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
