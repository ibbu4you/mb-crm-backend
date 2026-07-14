<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    private array $linkMap = ['lead' => Lead::class, 'contact' => Contact::class];

    private function scoped(Request $request)
    {
        $q = Task::query();
        $uid = $request->user()->id;
        if ($request->input('scope') === 'all' && $request->user()->can('tasks.assign')) {
            return $q;
        }
        if ($request->input('scope') === 'created') {
            return $q->where('created_by', $uid);
        }

        return $q->where(fn ($w) => $w->where('assignee_id', $uid)->orWhere('created_by', $uid));
    }

    public function index(Request $request)
    {
        $q = $this->scoped($request)->with(['assignee', 'creator']);
        if ($status = $request->input('status')) {
            $q->where('status', $status);
        }
        if ($priority = $request->input('priority')) {
            $q->where('priority', $priority);
        }
        if ($request->boolean('overdue')) {
            $q->whereDate('due_date', '<', today())->where('status', '!=', 'done');
        }
        if ($search = trim((string) $request->input('search'))) {
            $q->where('title', 'like', "%{$search}%");
        }

        return TaskResource::collection($q->orderByRaw("FIELD(priority,'urgent','high','medium','low')")->orderBy('due_date')->paginate($request->integer('per_page', 100)));
    }

    public function catalog()
    {
        return response()->json(Task::catalog());
    }

    public function stats(Request $request)
    {
        $byStatus = $this->scoped($request)->select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status');

        return response()->json([
            'total' => $this->scoped($request)->count(),
            'open' => $this->scoped($request)->where('status', '!=', 'done')->count(),
            'overdue' => $this->scoped($request)->whereDate('due_date', '<', today())->where('status', '!=', 'done')->count(),
            'by_status' => $byStatus,
        ]);
    }

    /** Badge: my tasks due today or overdue, not done. */
    public function badge(Request $request)
    {
        $count = Task::where('assignee_id', $request->user()->id)
            ->where('status', '!=', 'done')
            ->whereDate('due_date', '<=', today())->count();

        return response()->json(['count' => $count]);
    }

    public function assignees()
    {
        return response()->json(['data' => User::where('is_active', true)->orderBy('name')->get(['id', 'name'])]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        // Non-assigners can only create tasks for themselves.
        $assignee = ($data['assignee_id'] ?? null);
        if (! $request->user()->can('tasks.assign')) {
            $assignee = $request->user()->id;
        }

        $task = new Task([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'assignee_id' => $assignee ?? $request->user()->id,
            'created_by' => $request->user()->id,
            'due_date' => $data['due_date'] ?? null,
            'priority' => $data['priority'] ?? 'medium',
            'status' => $data['status'] ?? 'todo',
        ]);
        $this->applyLink($task, $data);
        $task->save();

        if ($task->assignee_id && $task->assignee_id !== $request->user()->id) {
            Notifier::send(User::find($task->assignee_id), [
                'type' => 'task',
                'event' => 'assigned',
                'title' => 'New task assigned',
                'message' => $task->title,
                'url' => '/tasks',
                'icon' => 'task',
            ]);
        }

        return (new TaskResource($task->load(['assignee', 'creator'])))->response()->setStatusCode(201);
    }

    public function show(Task $task)
    {
        return new TaskResource($task->load(['assignee', 'creator']));
    }

    public function update(Request $request, Task $task)
    {
        $data = $this->validateData($request);
        $previousAssignee = $task->assignee_id;
        $task->fill([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'priority' => $data['priority'] ?? $task->priority,
            'status' => $data['status'] ?? $task->status,
        ]);
        if ($request->user()->can('tasks.assign') && array_key_exists('assignee_id', $data)) {
            $task->assignee_id = $data['assignee_id'];
        }
        $this->applyLink($task, $data);
        $task->completed_at = $task->status === 'done' ? ($task->completed_at ?? now()) : null;
        $task->save();

        if ($task->assignee_id && $task->assignee_id !== $previousAssignee && $task->assignee_id !== $request->user()->id) {
            Notifier::send(User::find($task->assignee_id), [
                'type' => 'task',
                'event' => 'assigned',
                'title' => 'Task reassigned to you',
                'message' => $task->title,
                'url' => '/tasks',
                'icon' => 'task',
            ]);
        }

        return new TaskResource($task->load(['assignee', 'creator']));
    }

    public function updateStatus(Request $request, Task $task)
    {
        $data = $request->validate(['status' => ['required', Rule::in(Task::STATUSES)]]);
        $task->status = $data['status'];
        $task->completed_at = $data['status'] === 'done' ? now() : null;
        $task->save();

        return new TaskResource($task->load(['assignee', 'creator']));
    }

    public function destroy(Task $task)
    {
        $task->delete();

        return response()->json(['message' => 'Task deleted.']);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string'],
            'assignee_id' => ['nullable', 'exists:users,id'],
            'due_date' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'status' => ['nullable', Rule::in(Task::STATUSES)],
            'link_type' => ['nullable', Rule::in(array_keys($this->linkMap))],
            'link_id' => ['nullable', 'integer'],
        ]);
    }

    private function applyLink(Task $task, array $data): void
    {
        if (empty($data['link_type']) || empty($data['link_id'])) {
            $task->taskable_type = null;
            $task->taskable_id = null;
            $task->taskable_label = null;

            return;
        }
        $class = $this->linkMap[$data['link_type']];
        $entity = $class === Lead::class ? Lead::with('contact')->find($data['link_id']) : Contact::find($data['link_id']);
        if (! $entity) {
            return;
        }
        $task->taskable_type = $class;
        $task->taskable_id = $entity->id;
        $task->taskable_label = $entity instanceof Lead ? $entity->contact?->business_name : $entity->business_name;
    }
}
