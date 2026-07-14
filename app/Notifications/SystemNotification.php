<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Generic in-app (database) notification. The payload is stored verbatim in the
 * notification's `data` column and consumed by the frontend notification bell.
 *
 * Expected payload keys:
 *   type    — domain area (lead|task|follow_up|support|article|invoice|system)
 *   event   — short verb (created|assigned|due|reply|...)
 *   title   — bold headline
 *   message — the body line
 *   url     — SPA route to open on click (e.g. "/leads/12")
 *   icon    — icon hint for the UI (matches `type` by default)
 */
class SystemNotification extends Notification
{
    /** @param array<string, mixed> $payload */
    public function __construct(public array $payload) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return array_merge(['type' => 'system', 'event' => 'info'], $this->payload);
    }
}
