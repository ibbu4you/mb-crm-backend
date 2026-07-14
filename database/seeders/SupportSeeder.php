<?php

namespace Database\Seeders;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Seeder;

class SupportSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@malayznbeat.com')->value('id');
        $sales = User::where('email', 'sales@malayznbeat.com')->value('id') ?? $admin;
        $writer = User::where('email', 'writer@malayznbeat.com')->value('id') ?? $admin;

        $tickets = [
            ['Cannot export the monthly report', 'high', 'open', $sales, null],
            ['Login page shows a blank screen', 'urgent', 'in_progress', $writer, $admin],
            ['Request: add WhatsApp template', 'normal', 'waiting_user', $sales, $admin],
            ['Typo on the invoice PDF footer', 'low', 'resolved', $writer, $admin],
            ['Pipeline drag-and-drop is slow', 'normal', 'in_progress', $sales, $admin],
            ['How do I reset a client password?', 'low', 'closed', $writer, $admin],
        ];

        foreach ($tickets as [$subject, $priority, $status, $reporter, $assignee]) {
            $ticket = SupportTicket::updateOrCreate(
                ['subject' => $subject],
                [
                    'code' => SupportTicket::nextCode(),
                    'description' => 'Details: '.$subject.'. Please advise.',
                    'priority' => $priority,
                    'status' => $status,
                    'reporter_id' => $reporter,
                    'assignee_id' => $assignee,
                    'last_activity_at' => now()->subHours(rand(1, 72)),
                    'resolved_at' => in_array($status, ['resolved', 'closed']) ? now()->subDays(1) : null,
                    'closed_at' => $status === 'closed' ? now()->subHours(6) : null,
                ],
            );

            if ($ticket->replies()->count() === 0) {
                if ($assignee) {
                    $ticket->replies()->create(['user_id' => $assignee, 'body' => 'Assigned to '.User::find($assignee)->name, 'is_system' => true]);
                    $ticket->replies()->create(['user_id' => $assignee, 'body' => "Thanks for reporting — we're looking into it.", 'is_system' => false]);
                }
                if (in_array($status, ['resolved', 'closed'])) {
                    $ticket->replies()->create(['user_id' => $assignee, 'body' => 'Status changed to '.ucfirst($status), 'is_system' => true]);
                }
            }
        }
    }
}
