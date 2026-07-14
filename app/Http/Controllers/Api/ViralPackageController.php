<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ViralPackageResource;
use App\Models\ViralDeliverable;
use App\Models\ViralPackage;
use App\Services\ViralPackageService;
use App\Support\ViralWorkflow;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ViralPackageController extends Controller
{
    public function __construct(private ViralPackageService $service) {}

    public function index(Request $request)
    {
        $q = ViralPackage::query()->with(['contact', 'salesRep', 'deliverables']);

        // Contributors (sales reps / tech team) see their own; managers see all.
        if (! $request->user()->can('viral.manage')) {
            $uid = $request->user()->id;
            $q->where(fn ($w) => $w->where('sales_rep_id', $uid)->orWhere('tech_team_id', $uid)
                ->orWhereHas('deliverables', fn ($d) => $d->where('assigned_to', $uid)));
        }

        if ($status = $request->input('status')) {
            $q->where('status', $status);
        }
        if ($search = trim((string) $request->input('search'))) {
            $q->where(fn ($w) => $w->where('code', 'like', "%{$search}%")
                ->orWhere('title', 'like', "%{$search}%")
                ->orWhereHas('contact', fn ($c) => $c->where('business_name', 'like', "%{$search}%")));
        }

        return ViralPackageResource::collection($q->latest()->paginate($request->integer('per_page', 20)));
    }

    public function stats()
    {
        return response()->json([
            'total' => ViralPackage::count(),
            'active' => ViralPackage::where('status', 'active')->count(),
            'completed' => ViralPackage::where('status', 'completed')->count(),
            'deliverables_open' => ViralDeliverable::where('stage', '!=', ViralWorkflow::APPROVED)->count(),
        ]);
    }

    public function catalog()
    {
        return response()->json(ViralWorkflow::catalog());
    }

    public function show(ViralPackage $viralPackage)
    {
        return new ViralPackageResource($viralPackage->load(['contact', 'salesRep', 'techTeam', 'deliverables.assignee', 'deliverables.history.changer']));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'contact_id' => ['required', 'exists:contacts,id'],
            'title' => ['nullable', 'string', 'max:190'],
            'with_landing' => ['boolean'],
            'owners' => ['array'],
            'owners.article' => ['nullable', 'exists:users,id'],
            'owners.social_post' => ['nullable', 'exists:users,id'],
            'owners.reel' => ['nullable', 'exists:users,id'],
            'owners.landing_page' => ['nullable', 'exists:users,id'],
        ]);

        $package = $this->service->create($data['contact_id'], $request->user(), $data);

        return (new ViralPackageResource($package->load(['contact', 'salesRep', 'deliverables'])))->response()->setStatusCode(201);
    }

    public function addDeliverable(Request $request, ViralPackage $viralPackage)
    {
        $data = $request->validate(['kind' => ['required', Rule::in(['social_post', 'reel', 'article', 'landing_page'])]]);
        $this->service->addDeliverable($viralPackage, $data['kind']);

        return $this->fresh($viralPackage);
    }

    public function removeDeliverable(ViralDeliverable $deliverable)
    {
        $package = $deliverable->package;
        $this->service->removeDeliverable($deliverable);

        return $this->fresh($package);
    }

    public function markDelivered(Request $request, ViralPackage $viralPackage)
    {
        $data = $request->validate(['delivered_at' => ['nullable', 'date']]);
        $date = ! empty($data['delivered_at']) ? \Illuminate\Support\Carbon::parse($data['delivered_at']) : null;
        $this->service->markDelivered($viralPackage, $date);

        return $this->fresh($viralPackage);
    }

    public function reassign(Request $request, ViralPackage $viralPackage)
    {
        $data = $request->validate([
            'sales_rep_id' => ['nullable', 'exists:users,id'],
            'tech_team_id' => ['nullable', 'exists:users,id'],
            'owners' => ['array'],
            'owners.*' => ['nullable', 'exists:users,id'],
        ]);
        $this->service->reassign($viralPackage, $data['sales_rep_id'] ?? null, $data['tech_team_id'] ?? null, $data['owners'] ?? []);

        return $this->fresh($viralPackage);
    }

    public function teamOptions()
    {
        return response()->json([
            'data' => \App\Models\User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function reopen(ViralPackage $viralPackage)
    {
        $this->service->reopen($viralPackage);

        return $this->fresh($viralPackage);
    }

    public function destroy(ViralPackage $viralPackage)
    {
        $viralPackage->delete();

        return response()->json(['message' => 'Package deleted.']);
    }

    private function fresh(ViralPackage $package): ViralPackageResource
    {
        return new ViralPackageResource($package->fresh()->load(['contact', 'salesRep', 'techTeam', 'deliverables.assignee', 'deliverables.history.changer']));
    }
}
