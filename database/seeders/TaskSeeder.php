<?php

namespace Database\Seeders;

use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;

class TaskSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@malayznbeat.com')->value('id');
        $sales = User::where('email', 'sales@malayznbeat.com')->value('id') ?? $admin;
        $writer = User::where('email', 'writer@malayznbeat.com')->value('id') ?? $admin;
        $lead = Lead::with('contact')->first();

        $tasks = [
            ['Prepare proposal for Sunway Property', 'high', 'in_progress', $sales, 2],
            ['Call back Nasi Kandar Pelita', 'urgent', 'todo', $sales, -1],
            ['Draft Ramadan campaign brief', 'medium', 'todo', $writer, 3],
            ['Review Q3 sales numbers', 'medium', 'blocked', $admin, 5],
            ['Send invoice reminder to overdue clients', 'high', 'todo', $admin, 0],
            ['Publish approved articles', 'low', 'done', $writer, -3],
            ['Follow up on Silver package renewal', 'medium', 'in_progress', $sales, 1],
            ['Update portfolio with new websites', 'low', 'todo', $writer, 7],
        ];

        foreach ($tasks as $i => [$title, $priority, $status, $assignee, $dueOffset]) {
            $task = Task::updateOrCreate(['title' => $title], [
                'description' => 'Auto-seeded task.',
                'assignee_id' => $assignee,
                'created_by' => $admin,
                'due_date' => today()->addDays($dueOffset),
                'priority' => $priority,
                'status' => $status,
                'completed_at' => $status === 'done' ? now()->subDay() : null,
            ]);
            if ($i === 0 && $lead) {
                $task->update(['taskable_type' => Lead::class, 'taskable_id' => $lead->id, 'taskable_label' => $lead->contact?->business_name]);
            }
        }
    }
}
