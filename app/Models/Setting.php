<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'is_encrypted'];

    protected function casts(): array
    {
        return ['is_encrypted' => 'boolean'];
    }

    public static function get(string $key, $default = null)
    {
        $row = Cache::rememberForever("setting.$key", fn () => static::where('key', $key)->first());
        if (! $row) {
            return $default;
        }

        return $row->is_encrypted && $row->value ? rescue(fn () => Crypt::decryptString($row->value), $default) : $row->value;
    }

    public static function put(string $key, $value, bool $encrypt = false): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $encrypt && $value ? Crypt::encryptString($value) : $value, 'is_encrypted' => $encrypt],
        );
        Cache::forget("setting.$key");
    }
}
