<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, LogsActivity, Notifiable, SoftDeletes;
    use HasRoles {
        hasPermissionTo as protected spatieHasPermissionTo;
    }

    /**
     * Enforce per-user permission denials at the single chokepoint every check
     * funnels through (can / canAny / the Gate / checkPermissionTo all delegate
     * here). Returning false revokes a permission even when a role grants it —
     * the deterministic override for spatie's additive-only model. The
     * Administrator is never denied.
     */
    public function hasPermissionTo($permission, $guardName = null): bool
    {
        $name = is_string($permission) ? $permission : ($permission->name ?? null);

        if ($name !== null
            && in_array($name, $this->denied_permissions ?? [], true)
            && ! $this->hasRole(\App\Support\Roles::SUPER_ADMIN)) {
            return false;
        }

        return $this->spatieHasPermissionTo($permission, $guardName);
    }

    /** Audit only non-sensitive profile fields (never the password hash). */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'username', 'email', 'phone', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'name',
        'username',
        'email',
        'phone',
        'avatar_path',
        'is_active',
        'last_login_at',
        'password',
        'denied_permissions',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
            'denied_permissions' => 'array',
        ];
    }

    /** Role + direct permissions, minus any explicitly denied for this user. */
    public function effectivePermissions(): \Illuminate\Support\Collection
    {
        $denied = $this->denied_permissions ?? [];

        return $this->getAllPermissions()->pluck('name')
            ->reject(fn ($name) => in_array($name, $denied, true))->values();
    }

    protected $appends = ['avatar_url'];

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path ? asset('storage/'.$this->avatar_path) : null;
    }
}
