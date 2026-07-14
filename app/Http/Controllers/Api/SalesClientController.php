<?php

namespace App\Http\Controllers\Api;

use App\Exports\ClientsExport;
use App\Exports\ClientsTemplateExport;
use App\Http\Controllers\Controller;
use App\Imports\ClientsImport;
use App\Models\Lead;
use App\Support\Pipeline;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class SalesClientController extends Controller
{
    public function export(Request $request)
    {
        $owner = $request->user()->can('sales.reports.view.all') ? null : $request->user()->id;

        return Excel::download(new ClientsExport($owner), 'clients-'.now()->format('Y-m-d').'.xlsx');
    }

    public function template()
    {
        return Excel::download(new ClientsTemplateExport, 'clients-import-template.xlsx');
    }

    public function import(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240']]);
        $import = new ClientsImport($request->user()->id);
        Excel::import($import, $request->file('file'));

        return response()->json(['imported' => $import->imported]);
    }

    private function scoped(Request $request)
    {
        $q = Lead::query()->with(['contact', 'owner'])->withCount('visits')->withMax('visits', 'visit_date');
        if (! $request->user()->can('sales.reports.view.all')) {
            $q->where('owner_id', $request->user()->id);
        }

        return $q;
    }

    public function stats(Request $request)
    {
        $all = $request->user()->can('sales.reports.view.all');
        $leads = fn () => Lead::query()->when(! $all, fn ($q) => $q->where('owner_id', $request->user()->id));

        $byStage = (clone $leads())->selectRaw('pipeline_stage, count(*) as c')->groupBy('pipeline_stage')->pluck('c', 'pipeline_stage');
        $visits = \App\Models\Visit::query()->when(! $all, fn ($q) => $q->where('user_id', $request->user()->id))->count();

        $ownerIds = (clone $leads())->whereNotNull('owner_id')->distinct()->pluck('owner_id');
        $salespeople = \App\Models\User::whereIn('id', $ownerIds)->orderBy('name')->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values();

        return response()->json([
            'total' => (clone $leads())->count(),
            'visits' => $visits,
            'potential' => (float) (clone $leads())->where('status', 'active')->sum('revenue_potential'),
            'won' => (clone $leads())->where('pipeline_stage', 'won')->count(),
            'by_stage' => $byStage,
            'salespeople' => $salespeople,
        ]);
    }

    public function index(Request $request)
    {
        $q = $this->scoped($request);

        if ($stage = $request->input('stage')) {
            $q->where('pipeline_stage', $stage);
        }
        if ($status = $request->input('status')) {
            $q->where('status', $status);
        }
        if ($owner = $request->input('owner')) {
            $q->where('owner_id', $owner);
        }
        if ($search = trim((string) $request->input('search'))) {
            $q->whereHas('contact', fn ($c) => $c->where('business_name', 'like', "%{$search}%")
                ->orWhere('contact_person', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%"));
        }

        $sort = $request->input('sort', 'recent');
        match ($sort) {
            'name' => $q->orderBy(\App\Models\Contact::select('business_name')->whereColumn('contacts.id', 'leads.contact_id')),
            'potential' => $q->orderByDesc('revenue_potential'),
            'visits' => $q->orderByDesc('visits_count'),
            default => $q->orderByDesc('visits_max_visit_date'),
        };

        $page = $q->paginate($request->integer('per_page', 25));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Lead $l) => $this->row($l)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
        ]);
    }

    public function show(Lead $lead)
    {
        $lead->load(['contact.owner', 'visits.salesperson', 'followUps.user', 'deals.user']);
        $visits = $lead->visits;

        return response()->json([
            'id' => $lead->id,
            'business_name' => $lead->contact?->business_name,
            'contact_person' => $lead->contact?->contact_person,
            'phone' => $lead->contact?->phone,
            'email' => $lead->contact?->email,
            'city' => $lead->contact?->city,
            'industry' => $lead->contact?->industry,
            'stage' => $lead->pipeline_stage,
            'stage_label' => Pipeline::label($lead->pipeline_stage),
            'status' => $lead->status,
            'owner' => $lead->contact?->owner?->only('id', 'name'),
            'revenue_potential' => (float) $lead->revenue_potential,
            'stats' => [
                'visits' => $visits->count(),
                'decision_makers' => $visits->where('decision_maker_met', true)->count(),
                'interested' => $visits->where('interested', true)->count(),
                'follow_ups_done' => $visits->where('follow_up_done', true)->count(),
                'deals_won' => $lead->deals->where('outcome', 'won')->count(),
                'revenue_actual' => (float) $lead->deals->where('outcome', 'won')->sum('actual_revenue'),
            ],
            'visits' => $visits->map(fn ($v) => [
                'id' => $v->id, 'visit_date' => $v->visit_date->toDateString(), 'stage_label' => Pipeline::label($v->visit_level),
                'person_met' => $v->person_met, 'decision_maker_met' => $v->decision_maker_met, 'interested' => $v->interested,
                'revenue_potential' => (float) $v->revenue_potential, 'notes' => $v->notes, 'photo_url' => $v->photo_url, 'salesperson' => $v->salesperson?->name,
            ]),
            'follow_ups' => $lead->followUps->map(fn ($f) => [
                'id' => $f->id, 'due_date' => $f->due_date->toDateString(), 'note' => $f->note, 'status' => $f->status,
            ]),
            'deals' => $lead->deals->map(fn ($d) => [
                'id' => $d->id, 'outcome' => $d->outcome, 'actual_revenue' => $d->actual_revenue !== null ? (float) $d->actual_revenue : null, 'closed_at' => $d->closed_at->toDateString(),
            ]),
        ]);
    }

    private function row(Lead $l): array
    {
        return [
            'id' => $l->id,
            'business_name' => $l->contact?->business_name,
            'contact_person' => $l->contact?->contact_person,
            'phone' => $l->contact?->phone,
            'industry' => $l->contact?->industry,
            'stage' => $l->pipeline_stage,
            'stage_label' => Pipeline::label($l->pipeline_stage),
            'status' => $l->status,
            'owner' => $l->owner?->only('id', 'name'),
            'revenue_potential' => (float) $l->revenue_potential,
            'visits_count' => $l->visits_count,
            'last_visit_at' => $l->visits_max_visit_date,
        ];
    }
}
