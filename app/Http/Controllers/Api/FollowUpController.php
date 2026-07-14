<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Http\Request;

class FollowUpController extends Controller
{
    private function scoped(Request $request)
    {
        $q = FollowUp::query()->with(['lead.contact', 'user']);
        if (! $request->user()->can('sales.reports.view.all')) {
            $q->where('user_id', $request->user()->id);
        }

        return $q;
    }

    public function index(Request $request)
    {
        $items = $this->scoped($request)->orderBy('due_date')->get()->map(fn (FollowUp $f) => $this->transform($f));

        return response()->json(['data' => $items->values()]);
    }

    /** Count of pending follow-ups due today or overdue — drives the nav badge. */
    public function badge(Request $request)
    {
        $count = $this->scoped($request)->where('status', 'pending')->whereDate('due_date', '<=', today())->count();

        return response()->json(['count' => $count]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'lead_id' => ['required', 'exists:leads,id'],
            'due_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $lead = Lead::findOrFail($data['lead_id']);
        $f = $lead->followUps()->create([
            'user_id' => $lead->owner_id ?? $request->user()->id,
            'due_date' => $data['due_date'],
            'note' => $data['note'] ?? null,
            'status' => 'pending',
        ]);

        Notifier::send(User::find($f->user_id), [
            'type' => 'follow_up',
            'event' => 'created',
            'title' => 'New follow-up',
            'message' => ($lead->contact?->business_name ?? 'Lead').' · due '.$f->due_date->toDateString(),
            'url' => '/sales/follow-ups',
            'icon' => 'follow_up',
        ], $request->user()->id);

        return response()->json(['data' => $this->transform($f->load(['lead.contact', 'user']))], 201);
    }

    public function complete(FollowUp $followUp)
    {
        $followUp->update(['status' => 'done', 'completed_at' => now()]);

        return response()->json(['data' => $this->transform($followUp->load(['lead.contact', 'user']))]);
    }

    public function reopen(FollowUp $followUp)
    {
        $followUp->update(['status' => 'pending', 'completed_at' => null]);

        return response()->json(['data' => $this->transform($followUp->load(['lead.contact', 'user']))]);
    }

    private function transform(FollowUp $f): array
    {
        $bucket = 'done';
        if ($f->status === 'pending') {
            $due = $f->due_date->startOfDay();
            $bucket = $due->isPast() && ! $due->isToday() ? 'overdue' : ($due->isToday() ? 'today' : 'upcoming');
        }

        return [
            'id' => $f->id,
            'lead_id' => $f->lead_id,
            'business_name' => $f->lead?->contact?->business_name,
            'due_date' => $f->due_date->toDateString(),
            'note' => $f->note,
            'status' => $f->status,
            'bucket' => $bucket,
            'user' => $f->user?->only('id', 'name'),
        ];
    }
}
