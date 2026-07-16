<?php

namespace App\Providers;

use App\Support\Roles;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Super Admin bypasses every permission/gate check. Per-user denials are
        // enforced in User::checkPermissionTo() instead of here — spatie registers
        // its own Gate::before ahead of ours, so ordering can't be relied on.
        Gate::before(function ($user, $ability) {
            return $user->hasRole(Roles::SUPER_ADMIN) ? true : null;
        });
    }
}
