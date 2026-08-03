<?php

namespace App\Http\Controllers\Api;

use App\Exports\ClientsExport;
use App\Exports\ClientsTemplateExport;
use App\Http\Controllers\Controller;
use App\Imports\ClientsImport;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Visit;
use App\Support\Pipeline;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Clients — the shared, per-user client book. A "client" is one business (contact)
 * that has either a field-sales lead or a content article. Each user sees the
 * businesses they work: those they own a field lead for OR are the article
 * sales-rep on. Admins / Managers (sales.reports.view.all) see everyone's.
 */
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

    /** Base set of client-businesses, scoped to what this user is allowed to see. */
    private function clients(Request $request)
    {
        $uid = $request->user()->id;
        $q = Contact::query()->where(fn ($w) => $w->has('leads')->orHas('articles'));

        if (! $request->user()->can('sales.reports.view.all')) {
            $q->where(fn ($w) => $w
                ->whereHas('leads', fn ($l) => $l->where('owner_id', $uid))
                ->orWhereHas('articles', fn ($a) => $a->where('sales_rep_id', $uid)));
        }

        return $q;
    }

    public function stats(Request $request)
    {
        $ids = (clone $this->clients($request))->pluck('id');
        $leads = fn () => Lead::whereIn('contact_id', $ids);

        return response()->json([
            'total' => $ids->count(),
            'visits' => Visit::whereHas('lead', fn ($l) => $l->whereIn('contact_id', $ids))->count(),
            'potential' => (float) (clone $leads())->where('status', 'active')->sum('revenue_potential'),
            'won' => (clone $leads())->where('pipeline_stage', 'won')->count(),
            'by_stage' => (clone $leads())->selectRaw('pipeline_stage, count(*) as c')->groupBy('pipeline_stage')->pluck('c', 'pipeline_stage'),
            'salespeople' => \App\Models\User::whereIn('id', (clone $leads())->whereNotNull('owner_id')->distinct()->pluck('owner_id'))
                ->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
        ]);
    }

    public function index(Request $request)
    {
        $q = $this->clients($request)
            ->with(['leads.owner', 'owner'])
            ->withCount('articles')
            ->withCount('visits')
            ->withMax('visits', 'visit_date');

        if ($search = trim((string) $request->input('search'))) {
            $q->where(fn ($c) => $c->where('business_name', 'like', "%{$search}%")
                ->orWhere('contact_person', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }
        if ($stage = $request->input('stage')) {
            $q->whereHas('leads', fn ($l) => $l->where('pipeline_stage', $stage));
        }
        if ($status = $request->input('status')) {
            $q->whereHas('leads', fn ($l) => $l->where('status', $status));
        }
        if ($owner = $request->input('owner')) {
            $q->whereHas('leads', fn ($l) => $l->where('owner_id', $owner));
        }

        $revenueSub = Lead::selectRaw('COALESCE(SUM(revenue_potential),0)')->whereColumn('leads.contact_id', 'contacts.id')->where('status', 'active');
        match ($request->input('sort', 'recent')) {
            'name' => $q->orderBy('business_name'),
            'potential' => $q->orderByDesc($revenueSub),
            'visits' => $q->orderByDesc('visits_count'),
            default => $q->orderByRaw('visits_max_visit_date IS NULL, visits_max_visit_date DESC')->orderByDesc('updated_at'),
        };

        $page = $q->paginate($request->integer('per_page', 25));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Contact $c) => $this->row($c)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
        ]);
    }

    /** Pick the business's primary lead: the most recent field lead, else the most recent lead. */
    private function primaryLead(Contact $c): ?Lead
    {
        $leads = $c->leads;

        return $leads->where('source', 'field')->sortByDesc('last_activity_at')->first()
            ?? $leads->sortByDesc('last_activity_at')->first();
    }

    private function row(Contact $c): array
    {
        $lead = $this->primaryLead($c);

        return [
            'id' => $c->id,
            'business_name' => $c->business_name,
            'contact_person' => $c->contact_person,
            'phone' => $c->phone,
            'industry' => $c->industry,
            'stage' => $lead?->pipeline_stage ?? '',
            'stage_label' => $lead ? Pipeline::label($lead->pipeline_stage) : '—',
            'status' => $lead?->status ?? 'active',
            'owner' => ($lead?->owner ?? $c->owner)?->only('id', 'name'),
            'revenue_potential' => (float) $c->leads->where('status', 'active')->sum('revenue_potential'),
            'visits_count' => (int) $c->visits_count,
            'last_visit_at' => $c->visits_max_visit_date,
            'articles_count' => (int) $c->articles_count,
        ];
    }

    public function show(Contact $contact)
    {
        $contact->load([
            'owner', 'leads.owner', 'leads.visits.salesperson', 'leads.deals', 'leads.followUps',
            'articles' => fn ($q) => $q->latest(),
            'articles.salesRep',
        ]);

        $lead = $this->primaryLead($contact);
        $visits = $contact->leads->flatMap->visits;
        $deals = $contact->leads->flatMap->deals;

        return response()->json([
            'id' => $contact->id,
            'lead_id' => $lead?->id,
            'business_name' => $contact->business_name,
            'contact_person' => $contact->contact_person,
            'phone' => $contact->phone,
            'email' => $contact->email,
            'city' => $contact->city,
            'industry' => $contact->industry,
            'stage' => $lead?->pipeline_stage ?? '',
            'stage_label' => $lead ? Pipeline::label($lead->pipeline_stage) : '—',
            'status' => $lead?->status ?? 'active',
            'owner' => ($lead?->owner ?? $contact->owner)?->only('id', 'name'),
            'revenue_potential' => (float) $contact->leads->where('status', 'active')->sum('revenue_potential'),
            'stats' => [
                'visits' => $visits->count(),
                'decision_makers' => $visits->where('decision_maker_met', true)->count(),
                'interested' => $visits->where('interested', true)->count(),
                'follow_ups_done' => $visits->where('follow_up_done', true)->count(),
                'deals_won' => $deals->where('outcome', 'won')->count(),
                'revenue_actual' => (float) $deals->where('outcome', 'won')->sum('actual_revenue'),
            ],
            'visits' => $visits->sortByDesc('visit_date')->values()->map(fn ($v) => [
                'id' => $v->id, 'visit_date' => $v->visit_date->toDateString(), 'stage_label' => Pipeline::label($v->visit_level),
                'person_met' => $v->person_met, 'decision_maker_met' => $v->decision_maker_met, 'interested' => $v->interested,
                'revenue_potential' => (float) $v->revenue_potential, 'notes' => $v->notes, 'photo_url' => $v->photo_url, 'salesperson' => $v->salesperson?->name,
            ]),
            'follow_ups' => $contact->leads->flatMap->followUps->map(fn ($f) => [
                'id' => $f->id, 'due_date' => $f->due_date->toDateString(), 'note' => $f->note, 'status' => $f->status,
            ])->values(),
            'deals' => $deals->map(fn ($d) => [
                'id' => $d->id, 'outcome' => $d->outcome, 'actual_revenue' => $d->actual_revenue !== null ? (float) $d->actual_revenue : null, 'closed_at' => $d->closed_at->toDateString(),
            ])->values(),
            'articles' => $contact->articles->map(fn ($a) => [
                'id' => $a->id, 'code' => $a->article_code, 'title' => $a->title,
                'stage' => $a->current_stage, 'stage_label' => $a->stage_label, 'sales_rep' => $a->salesRep?->name,
            ])->values(),
        ]);
    }
}
