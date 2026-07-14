<?php

namespace App\Http\Controllers\Api;

use App\Exports\ClientsTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeadResource;
use App\Imports\LeadsImport;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\User;
use App\Support\Notifier;
use App\Support\Pipeline;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class LeadController extends Controller
{
    private function scoped(Request $request)
    {
        $q = Lead::query();
        if (! $request->user()->can('leads.view.all')) {
            $q->where('owner_id', $request->user()->id);
        }

        return $q;
    }

    public function index(Request $request)
    {
        $q = $this->scoped($request)->with(['contact', 'type', 'owner']);

        if ($stage = $request->input('stage')) {
            $q->where('pipeline_stage', $stage);
        }
        if ($status = $request->input('status')) {
            $q->where('status', $status);
        }
        if ($type = $request->input('type')) {
            $q->where('lead_type_id', $type);
        }
        if ($source = $request->input('source')) {
            $q->where('source', $source);
        }
        if ($owner = $request->input('owner')) {
            $q->where('owner_id', $owner);
        }
        if ($search = trim((string) $request->input('search'))) {
            $q->where(function ($w) use ($search) {
                $w->where('title', 'like', "%{$search}%")
                    ->orWhereHas('contact', fn ($c) => $c->where('business_name', 'like', "%{$search}%")
                        ->orWhere('contact_person', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));
            });
        }

        return LeadResource::collection($q->latest('last_activity_at')->latest()->paginate($request->integer('per_page', 20)));
    }

    public function stats(Request $request)
    {
        // Scope the whole picture to a service line / source when requested, but
        // NOT to stage/status — the stage breakdown must stay complete for chips.
        $base = function () use ($request) {
            $q = $this->scoped($request);
            if ($type = $request->input('type')) {
                $q->where('lead_type_id', $type);
            }
            if ($source = $request->input('source')) {
                $q->where('source', $source);
            }

            return $q;
        };

        $byStage = (clone $base())->selectRaw('pipeline_stage, count(*) as c')->groupBy('pipeline_stage')->pluck('c', 'pipeline_stage');
        $open = (clone $base())->where('status', 'active')->count();
        $won = (clone $base())->where('status', 'won')->whereMonth('updated_at', now()->month)->whereYear('updated_at', now()->year)->count();
        $potential = (clone $base())->where('status', 'active')->sum('revenue_potential');

        return response()->json([
            'total' => (clone $base())->count(),
            'open' => $open,
            'won_this_month' => $won,
            'potential' => (float) $potential,
            'by_stage' => $byStage,
        ]);
    }

    /** MB Leads-style dashboard: funnel KPIs + per-type + recent. */
    public function dashboard(Request $request)
    {
        $base = fn () => $this->scoped($request);
        $stageIn = fn (array $stages) => (clone $base())->whereIn('pipeline_stage', $stages)->count();

        $byType = (clone $base())
            ->select('lead_type_id', DB::raw('count(*) as c'))->groupBy('lead_type_id')->pluck('c', 'lead_type_id');
        $types = \App\Models\LeadType::orderBy('sort_order')->get()->map(fn ($t) => [
            'id' => $t->id, 'name' => $t->name, 'color' => $t->color, 'count' => (int) ($byType[$t->id] ?? 0),
        ]);

        $recent = (clone $base())->with(['contact', 'type'])->latest()->limit(8)->get()
            ->map(fn (Lead $l) => [
                'id' => $l->id,
                'name' => $l->contact?->contact_person ?? $l->contact?->business_name,
                'business' => $l->contact?->business_name,
                'phone' => $l->contact?->phone,
                'type' => $l->type?->name,
                'stage' => $l->pipeline_stage,
                'stage_label' => Pipeline::label($l->pipeline_stage),
                'source' => $l->source,
                'created_at' => $l->created_at,
            ]);

        return response()->json([
            'total' => (clone $base())->count(),
            'new' => $stageIn(['intake']),
            'contacted' => $stageIn(['cold', 'warm']),
            'interested' => $stageIn(['qualified', 'opportunity', 'proposal']),
            'won' => (clone $base())->where('status', 'won')->count(),
            'lost' => (clone $base())->where('status', 'lost')->count(),
            'by_source' => (clone $base())->select('source', DB::raw('count(*) as c'))->groupBy('source')->pluck('c', 'source'),
            'by_type' => $types,
            'recent' => $recent,
        ]);
    }

    /** Stream leads as CSV (respects the same filters as index). */
    public function export(Request $request)
    {
        $q = $this->scoped($request)->with(['contact', 'type', 'owner']);
        if ($stage = $request->input('stage')) {
            $q->where('pipeline_stage', $stage);
        }
        if ($type = $request->input('type')) {
            $q->where('lead_type_id', $type);
        }
        if ($source = $request->input('source')) {
            $q->where('source', $source);
        }

        $rows = $q->latest()->get();
        $filename = 'leads-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['ID', 'Business', 'Contact', 'Phone', 'Email', 'Type', 'Stage', 'Status', 'Source', 'Revenue Potential', 'Owner', 'Created']);
            foreach ($rows as $l) {
                fputcsv($out, [
                    $l->id, $l->contact?->business_name, $l->contact?->contact_person, $l->contact?->phone, $l->contact?->email,
                    $l->type?->name, Pipeline::label($l->pipeline_stage), $l->status, $l->source,
                    $l->revenue_potential, $l->owner?->name, $l->created_at?->toDateString(),
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** Download a spreadsheet template with the expected column headings. */
    public function importTemplate()
    {
        return Excel::download(new ClientsTemplateExport, 'leads-import-template.xlsx');
    }

    /** Import leads from an uploaded .xlsx/.xls/.csv file. */
    public function import(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:10240']]);

        $import = new LeadsImport($request->user()->id);
        Excel::import($import, $request->file('file'));

        return response()->json(['imported' => $import->imported, 'skipped' => $import->skipped]);
    }

    /** Reference data for the frontend (pipeline stages + sources). */
    public function catalog()
    {
        return response()->json([
            'stages' => Pipeline::catalog(),
            'sources' => ['whatsapp', 'web', 'field', 'manual', 'referral'],
            'statuses' => ['active', 'won', 'lost', 'dormant'],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'contact_id' => ['nullable', 'exists:contacts,id'],
            // inline contact (when contact_id not supplied)
            'business_name' => ['required_without:contact_id', 'string', 'max:190'],
            'contact_person' => ['nullable', 'string', 'max:190'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:120'],
            // lead fields
            'title' => ['nullable', 'string', 'max:190'],
            'lead_type_id' => ['nullable', 'exists:lead_types,id'],
            'pipeline_stage' => ['nullable', Rule::in(Pipeline::all())],
            'source' => ['nullable', Rule::in(['whatsapp', 'web', 'field', 'manual', 'referral'])],
            'revenue_potential' => ['nullable', 'numeric', 'min:0'],
            'expected_close_date' => ['nullable', 'date'],
            'owner_id' => ['nullable', 'exists:users,id'],
            'notes' => ['nullable', 'string'],
        ]);

        $contactId = $data['contact_id'] ?? null;
        if (! $contactId) {
            $contact = Contact::create([
                'business_name' => $data['business_name'],
                'contact_person' => $data['contact_person'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'industry' => $data['industry'] ?? null,
                'source' => $data['source'] ?? 'manual',
                'owner_id' => $data['owner_id'] ?? $request->user()->id,
                'created_by' => $request->user()->id,
            ]);
            $contactId = $contact->id;
        }

        $lead = Lead::create([
            'contact_id' => $contactId,
            'title' => $data['title'] ?? null,
            'lead_type_id' => $data['lead_type_id'] ?? null,
            'pipeline_stage' => $data['pipeline_stage'] ?? 'intake',
            'status' => 'active',
            'source' => $data['source'] ?? 'manual',
            'owner_id' => $data['owner_id'] ?? $request->user()->id,
            'revenue_potential' => $data['revenue_potential'] ?? 0,
            'expected_close_date' => $data['expected_close_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'last_activity_at' => now(),
        ]);

        $owner = $lead->owner_id ? User::find($lead->owner_id) : null;
        Notifier::send($owner, [
            'type' => 'lead',
            'event' => 'assigned',
            'title' => 'New lead assigned',
            'message' => ($lead->contact?->business_name ?? 'New lead').($lead->title ? ' — '.$lead->title : ''),
            'url' => '/leads/'.$lead->id,
            'icon' => 'lead',
        ], $request->user()->id);

        return (new LeadResource($lead->load(['contact', 'type', 'owner'])))->response()->setStatusCode(201);
    }

    public function show(Lead $lead)
    {
        return new LeadResource($lead->load(['contact.owner', 'type', 'owner', 'comments.user']));
    }

    public function update(Request $request, Lead $lead)
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:190'],
            'lead_type_id' => ['nullable', 'exists:lead_types,id'],
            'source' => ['nullable', Rule::in(['whatsapp', 'web', 'field', 'manual', 'referral'])],
            'revenue_potential' => ['nullable', 'numeric', 'min:0'],
            'expected_close_date' => ['nullable', 'date'],
            'owner_id' => ['nullable', 'exists:users,id'],
            'notes' => ['nullable', 'string'],
        ]);
        $data['last_activity_at'] = now();
        $lead->update($data);

        return new LeadResource($lead->load(['contact', 'type', 'owner']));
    }

    public function updateStage(Request $request, Lead $lead)
    {
        $data = $request->validate([
            'pipeline_stage' => ['required', Rule::in(Pipeline::all())],
        ]);
        $stage = $data['pipeline_stage'];

        $lead->pipeline_stage = $stage;
        $lead->status = match ($stage) {
            'won' => 'won',
            'lost' => 'lost',
            default => 'active',
        };
        $lead->last_activity_at = now();
        $lead->save();

        return new LeadResource($lead->load(['contact', 'type', 'owner']));
    }

    public function destroy(Lead $lead)
    {
        $lead->delete();

        return response()->json(['message' => 'Lead deleted.']);
    }
}
