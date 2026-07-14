<?php

namespace App\Support;

use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Support\Collection;

/**
 * Small façade over Laravel's database notifications so controllers can fire
 * in-app alerts with a single call. Always skips null recipients, dedupes by id
 * and (optionally) skips the acting user so people aren't notified of their own
 * actions.
 */
class Notifier
{
    /**
     * @param  User|iterable<User>|null  $users
     * @param  array<string, mixed>  $payload
     */
    public static function send($users, array $payload, ?int $exceptId = null): void
    {
        $collection = $users instanceof Collection
            ? $users
            : collect(is_iterable($users) ? $users : [$users]);

        $collection
            ->filter()
            ->unique('id')
            ->when($exceptId !== null, fn (Collection $c) => $c->reject(fn (User $u) => $u->id === $exceptId))
            ->each(fn (User $u) => $u->notify(new SystemNotification($payload)));
    }

    /**
     * Notify every active user who holds the given permission.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function toPermission(string $permission, array $payload, ?int $exceptId = null): void
    {
        $users = User::where('is_active', true)->get()
            ->filter(fn (User $u) => $u->can($permission));

        self::send($users, $payload, $exceptId);
    }
}
