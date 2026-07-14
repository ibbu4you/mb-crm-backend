<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SupportTicketResource;
use App\Models\SupportAttachment;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Models\User;
use App\Notifications\SupportEvent;
use App\Support\SupportDesk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SupportTicketController extends Controller
{
    public function index(Request $request)
    {
        $q = SupportTicket::query()->with(['reporter', 'assignee'])->withCount('replies');

        // Non-handlers only see their own tickets.
        if (! $request->user()->can('support.handle')) {
            $q->where('reporter_id', $request->user()->id);
        }
        if ($request->boolean('mine')) {
            $q->where('assignee_id', $request->user()->id);
        }
        if ($status = $request->input('status')) {
            $q->where('status', $status);
        }
        if ($priority = $request->input('priority')) {
            $q->where('priority', $priority);
        }
        if ($search = trim((string) $request->input('search'))) {
            $q->where(fn ($w) => $w->where('subject', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
        }

        return SupportTicketResource::collection($q->latest('last_activity_at')->latest()->paginate($request->integer('per_page', 30)));
    }

    public function stats(Request $request)
    {
        $base = fn () => SupportTicket::query()->when(! $request->user()->can('support.handle'), fn ($q) => $q->where('reporter_id', $request->user()->id));
        $byStatus = (clone $base())->select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status');

        return response()->json([
            'total' => (clone $base())->count(),
            'open' => (int) ($byStatus['open'] ?? 0) + (int) ($byStatus['in_progress'] ?? 0) + (int) ($byStatus['waiting_user'] ?? 0),
            'resolved' => (int) ($byStatus['resolved'] ?? 0),
            'urgent' => (clone $base())->where('priority', 'urgent')->whereNotIn('status', ['resolved', 'closed'])->count(),
            'by_status' => $byStatus,
        ]);
    }

    public function catalog()
    {
        return response()->json(SupportDesk::catalog());
    }

    /** Users who can handle tickets — for the assign picker. */
    public function agents()
    {
        return response()->json(['data' => User::permission('support.handle')->where('is_active', true)->get(['id', 'name'])]);
    }

    public function show(Request $request, SupportTicket $ticket)
    {
        $this->ensureAccess($request, $ticket);

        return new SupportTicketResource($ticket->load(['reporter', 'assignee', 'attachments', 'replies.user', 'replies.attachments']));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', Rule::in(array_keys(SupportDesk::PRIORITIES))],
            'attachments.*' => ['file', 'max:20480'],
        ]);

        $ticket = SupportTicket::create([
            'code' => SupportTicket::nextCode(),
            'subject' => $data['subject'],
            'description' => $data['description'] ?? null,
            'priority' => $data['priority'] ?? 'normal',
            'status' => 'open',
            'reporter_id' => $request->user()->id,
            'last_activity_at' => now(),
        ]);

        $this->saveAttachments($request, $ticket);

        return (new SupportTicketResource($ticket->load(['reporter', 'assignee', 'attachments'])))->response()->setStatusCode(201);
    }

    public function reply(Request $request, SupportTicket $ticket)
    {
        $this->ensureAccess($request, $ticket);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'attachments.*' => ['file', 'max:20480'],
        ]);

        $reply = $ticket->replies()->create(['user_id' => $request->user()->id, 'body' => $data['body'], 'is_system' => false]);
        $this->saveAttachments($request, $reply);

        // If a handler replies to an open ticket, move it in-progress.
        if ($ticket->status === 'open' && $request->user()->can('support.handle')) {
            $ticket->status = 'in_progress';
        }
        $ticket->last_activity_at = now();
        $ticket->save();

        // Notify the other party.
        $other = $request->user()->id === $ticket->reporter_id ? $ticket->assignee : $ticket->reporter;
        $this->notify($other, $ticket, 'reply', "New reply on {$ticket->code}: {$ticket->subject}");

        return new SupportTicketResource($ticket->fresh()->load(['reporter', 'assignee', 'attachments', 'replies.user', 'replies.attachments']));
    }

    public function updateStatus(Request $request, SupportTicket $ticket)
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(SupportDesk::STATUSES))]]);
        $status = $data['status'];

        $ticket->status = $status;
        $ticket->resolved_at = $status === 'resolved' ? now() : ($status === 'closed' ? $ticket->resolved_at : null);
        $ticket->closed_at = $status === 'closed' ? now() : null;
        $ticket->last_activity_at = now();
        $ticket->save();

        $this->systemReply($ticket, $request->user(), 'Status changed to '.SupportDesk::statusLabel($status));
        $this->notify($ticket->reporter, $ticket, 'status', "{$ticket->code} is now ".SupportDesk::statusLabel($status));

        return $this->fresh($ticket);
    }

    public function updatePriority(Request $request, SupportTicket $ticket)
    {
        $data = $request->validate(['priority' => ['required', Rule::in(array_keys(SupportDesk::PRIORITIES))]]);
        $ticket->update(['priority' => $data['priority'], 'last_activity_at' => now()]);
        $this->systemReply($ticket, $request->user(), 'Priority set to '.SupportDesk::priorityLabel($data['priority']));

        return $this->fresh($ticket);
    }

    public function assign(Request $request, SupportTicket $ticket)
    {
        $data = $request->validate(['assignee_id' => ['nullable', 'exists:users,id']]);
        $ticket->update(['assignee_id' => $data['assignee_id'] ?? null, 'last_activity_at' => now()]);

        if ($data['assignee_id'] ?? null) {
            $assignee = User::find($data['assignee_id']);
            $this->systemReply($ticket, $request->user(), "Assigned to {$assignee->name}");
            $this->notify($assignee, $ticket, 'assigned', "You were assigned {$ticket->code}: {$ticket->subject}");
        } else {
            $this->systemReply($ticket, $request->user(), 'Unassigned');
        }

        return $this->fresh($ticket);
    }

    public function destroy(SupportTicket $ticket)
    {
        $ticket->delete();

        return response()->json(['message' => 'Ticket deleted.']);
    }

    // --- helpers ---

    private function ensureAccess(Request $request, SupportTicket $ticket): void
    {
        abort_unless(
            $request->user()->can('support.handle') || $ticket->reporter_id === $request->user()->id,
            403,
            'You do not have access to this ticket.'
        );
    }

    private function saveAttachments(Request $request, $model): void
    {
        foreach ((array) $request->file('attachments', []) as $file) {
            $path = $file->store('support', 'public');
            $model->attachments()->create([
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime' => $file->getClientMimeType(),
                'uploaded_by' => $request->user()->id,
            ]);
        }
    }

    private function systemReply(SupportTicket $ticket, User $actor, string $message): void
    {
        $ticket->replies()->create(['user_id' => $actor->id, 'body' => $message, 'is_system' => true]);
    }

    private function notify(?User $user, SupportTicket $ticket, string $event, string $message): void
    {
        if ($user && $user->id !== auth()->id()) {
            $user->notify(new SupportEvent($ticket, $event, $message));
        }
    }

    private function fresh(SupportTicket $ticket): SupportTicketResource
    {
        return new SupportTicketResource($ticket->fresh()->load(['reporter', 'assignee', 'attachments', 'replies.user', 'replies.attachments']));
    }
}
