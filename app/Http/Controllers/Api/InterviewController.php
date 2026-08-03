<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadType;
use Illuminate\Http\Request;

/**
 * Interview / appointment registrations — the "Free Spotlight" bookings that come
 * in from the appointment landing page and the WhatsApp funnel. These are booking
 * requests, not owned sales leads, so the whole sales team sees ALL of them
 * (no owner scoping), unlike the Leads module.
 */
class InterviewController extends Controller
{
    public function index(Request $request)
    {
        $typeId = LeadType::where('name', 'Free Spotlight')->value('id');

        $q = Lead::query()->with(['contact', 'owner'])
            ->when(
                $typeId,
                fn ($qq) => $qq->where('lead_type_id', $typeId),
                fn ($qq) => $qq->whereRaw("JSON_EXTRACT(meta, '$.campaign') = 'free_spotlight'")
            );

        if ($search = trim((string) $request->input('search'))) {
            $q->whereHas('contact', fn ($c) => $c
                ->where('business_name', 'like', "%{$search}%")
                ->orWhere('contact_person', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }
        if ($stage = $request->input('stage')) {
            $q->where('pipeline_stage', $stage);
        }

        $page = $q->latest('last_activity_at')->latest('id')->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => collect($page->items())->map(fn (Lead $l) => [
                'id' => $l->id,
                'business_name' => $l->contact?->business_name,
                'contact_person' => $l->contact?->contact_person,
                'phone' => $l->contact?->phone,
                'email' => $l->contact?->email,
                'industry' => $l->contact?->industry,
                'city' => $l->contact?->city,
                'source' => $l->source,
                'stage' => $l->pipeline_stage,
                'status' => $l->status,
                'owner' => $l->owner?->only('id', 'name'),
                'submitted_at' => $l->created_at,
                'last_activity_at' => $l->last_activity_at,
                'notes' => $l->notes,
                'form' => $l->meta['form'] ?? (object) [],
            ]),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ]);
    }
}
