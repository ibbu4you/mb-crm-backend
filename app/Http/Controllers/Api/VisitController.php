<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Visit;
use App\Support\Pipeline;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VisitController extends Controller
{
    public function index(Request $request)
    {
        $q = Visit::query()->with(['lead.contact', 'salesperson']);
        if (! $request->user()->can('sales.reports.view.all')) {
            $q->where('user_id', $request->user()->id);
        }
        if ($leadId = $request->input('lead_id')) {
            $q->where('lead_id', $leadId);
        }

        $visits = $q->latest('visit_date')->latest()->paginate($request->integer('per_page', 30));

        return response()->json([
            'data' => $visits->getCollection()->map(fn (Visit $v) => $this->transform($v)),
            'meta' => [
                'current_page' => $visits->currentPage(),
                'last_page' => $visits->lastPage(),
                'total' => $visits->total(),
                'per_page' => $visits->perPage(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'lead_id' => ['nullable', 'exists:leads,id'],
            'business_name' => ['required_without:lead_id', 'string', 'max:190'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'visit_date' => ['required', 'date', 'before_or_equal:today'],
            'visit_level' => ['required', Rule::in(Pipeline::STAGES)],
            'person_met' => ['nullable', 'string', 'max:190'],
            'decision_maker_met' => ['boolean'],
            'interested' => ['boolean'],
            'follow_up_done' => ['boolean'],
            'revenue_potential' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'photo' => ['nullable', 'image', 'max:5120'],
            // geo check-in
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'integer'],
            'address' => ['nullable', 'string', 'max:500'],
            // optional follow-up
            'follow_up_date' => ['nullable', 'date', 'after_or_equal:today'],
            'follow_up_note' => ['nullable', 'string', 'max:255'],
        ]);

        // Resolve or quick-create the lead.
        if (! empty($data['lead_id'])) {
            $lead = Lead::findOrFail($data['lead_id']);
        } else {
            $contact = Contact::create([
                'business_name' => $data['business_name'],
                'phone' => $data['contact_phone'] ?? null,
                'source' => 'field',
                'owner_id' => $request->user()->id,
                'created_by' => $request->user()->id,
            ]);
            $lead = Lead::create([
                'contact_id' => $contact->id,
                'pipeline_stage' => $data['visit_level'],
                'status' => 'active',
                'source' => 'field',
                'owner_id' => $request->user()->id,
                'revenue_potential' => $data['revenue_potential'] ?? 0,
                'last_activity_at' => now(),
            ]);
        }

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('visits', 'public');
        }

        // Credit the lead owner (an admin may log on their behalf).
        $ownerId = $lead->owner_id ?? $request->user()->id;

        $visit = Visit::create([
            'lead_id' => $lead->id,
            'user_id' => $ownerId,
            'visit_date' => $data['visit_date'],
            'visit_level' => $data['visit_level'],
            'person_met' => $data['person_met'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'decision_maker_met' => $data['decision_maker_met'] ?? false,
            'interested' => $data['interested'] ?? false,
            'follow_up_done' => $data['follow_up_done'] ?? false,
            'revenue_potential' => $data['revenue_potential'] ?? 0,
            'notes' => $data['notes'] ?? null,
            'photo_path' => $photoPath,
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
            'accuracy' => $data['accuracy'] ?? null,
            'address' => $data['address'] ?? null,
        ]);

        // Keep the lead's headline revenue potential in step with the latest visit.
        if (($data['revenue_potential'] ?? 0) > 0) {
            $lead->update(['revenue_potential' => $data['revenue_potential']]);
        }
        $lead->refreshPipeline();

        // Optional scheduled follow-up.
        if (! empty($data['follow_up_date'])) {
            $lead->followUps()->create([
                'visit_id' => $visit->id,
                'user_id' => $ownerId,
                'due_date' => $data['follow_up_date'],
                'note' => $data['follow_up_note'] ?? null,
                'status' => 'pending',
            ]);
        }

        return response()->json(['data' => $this->transform($visit->load(['lead.contact', 'salesperson']))], 201);
    }

    private function transform(Visit $v): array
    {
        return [
            'id' => $v->id,
            'lead_id' => $v->lead_id,
            'business_name' => $v->lead?->contact?->business_name,
            'visit_date' => $v->visit_date->toDateString(),
            'visit_level' => $v->visit_level,
            'stage_label' => Pipeline::label($v->visit_level),
            'person_met' => $v->person_met,
            'contact_phone' => $v->contact_phone,
            'decision_maker_met' => $v->decision_maker_met,
            'interested' => $v->interested,
            'follow_up_done' => $v->follow_up_done,
            'revenue_potential' => (float) $v->revenue_potential,
            'notes' => $v->notes,
            'photo_url' => $v->photo_url,
            'salesperson' => $v->salesperson?->only('id', 'name'),
            'created_at' => $v->created_at,
        ];
    }
}
