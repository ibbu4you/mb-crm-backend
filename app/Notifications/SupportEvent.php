<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use Illuminate\Notifications\Notification;

class SupportEvent extends Notification
{
    public function __construct(
        public SupportTicket $ticket,
        public string $event,
        public string $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'support',
            'event' => $this->event,
            'ticket_id' => $this->ticket->id,
            'code' => $this->ticket->code,
            'subject' => $this->ticket->subject,
            'title' => $this->ticket->code,
            'message' => $this->message,
            'url' => '/support',
            'icon' => 'support',
        ];
    }
}
