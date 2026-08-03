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
    /** An interview is a registration carrying the intake form (meta.form — the
     *  lead's "Additional Details"), or a Free Spotlight registration. */
    private function base()
    {
        $typeId = LeadType::where('name', 'Free Spotlight')->value('id');

        // A real interview registration carries interview details (the intake form
        // asks "Preferred Interview Mode / Language / slot"), so its meta contains
        // "Interview". This excludes junk "New Form" submissions that only hold a
        // form id/name and no actual booking data.
        return Lead::query()->where(function ($w) use ($typeId) {
            $w->whereRaw("meta LIKE '%Interview%'")
                ->orWhereRaw("JSON_EXTRACT(meta, '$.campaign') = 'free_spotlight'");
            if ($typeId) {
                $w->orWhere('lead_type_id', $typeId);
            }
        });
    }

    public function stats()
    {
        return response()->json([
            'total' => $this->base()->count(),
            'this_month' => $this->base()->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count(),
            'whatsapp' => $this->base()->where('source', 'whatsapp')->count(),
            'appointment' => $this->base()->where('source', 'web')->count(),
            'by_stage' => $this->base()->selectRaw('pipeline_stage, count(*) as c')->groupBy('pipeline_stage')->pluck('c', 'pipeline_stage'),
        ]);
    }

    public function index(Request $request)
    {
        $q = $this->base()->with(['contact', 'owner']);

        if ($source = $request->input('source')) {
            $q->where('source', $source);
        }
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
                'form' => $this->cleanForm($l->meta['form'] ?? []),
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

    /** Normalise intake-form labels: WhatsApp stores keys underscored, the landing
     * page stores them spaced — humanise so both read the same and columns match. */
    private function cleanForm($form): object|array
    {
        $out = [];
        foreach ((array) $form as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $out[str_replace('_', ' ', (string) $k)] = $v;
        }

        return $out ?: (object) [];
    }
}
