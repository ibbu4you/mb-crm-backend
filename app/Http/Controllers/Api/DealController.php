<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DealController extends Controller
{
    /** Close a lead as won/lost — creates a deal and marks the lead terminal. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'lead_id' => ['required', 'exists:leads,id'],
            'outcome' => ['required', Rule::in(['won', 'lost'])],
            'actual_revenue' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'closed_at' => ['nullable', 'date'],
        ]);

        $lead = Lead::findOrFail($data['lead_id']);

        $deal = Deal::create([
            'lead_id' => $lead->id,
            'user_id' => $lead->owner_id ?? $request->user()->id,
            'outcome' => $data['outcome'],
            'actual_revenue' => $data['outcome'] === 'won' ? ($data['actual_revenue'] ?? 0) : null,
            'notes' => $data['notes'] ?? null,
            'closed_at' => $data['closed_at'] ?? today(),
        ]);

        $lead->update([
            'status' => $data['outcome'],
            'pipeline_stage' => $data['outcome'],
            'last_activity_at' => now(),
        ]);

        return response()->json(['data' => $deal], 201);
    }

    public function index(Request $request)
    {
        $q = Deal::query()->with(['lead.contact', 'user']);
        if (! $request->user()->can('sales.reports.view.all')) {
            $q->where('user_id', $request->user()->id);
        }

        return response()->json([
            'data' => $q->latest('closed_at')->limit(100)->get()->map(fn (Deal $d) => [
                'id' => $d->id,
                'business_name' => $d->lead?->contact?->business_name,
                'outcome' => $d->outcome,
                'actual_revenue' => $d->actual_revenue !== null ? (float) $d->actual_revenue : null,
                'closed_at' => $d->closed_at->toDateString(),
                'user' => $d->user?->only('id', 'name'),
            ]),
        ]);
    }
}
