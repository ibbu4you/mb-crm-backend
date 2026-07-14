<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ViralPackageResource;
use App\Models\ViralDeliverable;
use App\Services\ViralPackageService;
use Illuminate\Http\Request;

class ViralDeliverableController extends Controller
{
    public function __construct(private ViralPackageService $service) {}

    public function pickUp(Request $request, ViralDeliverable $deliverable)
    {
        return $this->fresh($this->service->pickUp($deliverable, $request->user()));
    }

    public function submit(Request $request, ViralDeliverable $deliverable)
    {
        $data = $request->validate([
            'caption' => ['nullable', 'string'],
            'hashtags' => ['nullable', 'string'],
            'target_audience' => ['nullable', 'string'],
            'landing_page_url' => ['nullable', 'string', 'max:1000'],
            'file' => ['nullable', 'file', 'max:51200'],
        ]);

        return $this->fresh($this->service->submit($deliverable, $request->user(), $data, $request->file('file')));
    }

    public function approve(Request $request, ViralDeliverable $deliverable)
    {
        return $this->fresh($this->service->approve($deliverable, $request->user()));
    }

    public function requestCorrection(Request $request, ViralDeliverable $deliverable)
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        return $this->fresh($this->service->requestCorrection($deliverable, $request->user(), $data['notes'] ?? null));
    }

    private function fresh(ViralDeliverable $deliverable): ViralPackageResource
    {
        $package = $deliverable->package()->with(['contact', 'salesRep', 'techTeam', 'deliverables.assignee', 'deliverables.history.changer'])->first();

        return new ViralPackageResource($package);
    }
}
