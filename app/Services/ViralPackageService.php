<?php

namespace App\Services;

use App\Models\User;
use App\Models\ViralDeliverable;
use App\Models\ViralPackage;
use App\Support\ViralWorkflow as VW;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class ViralPackageService
{
    /** Create a package for a contact and auto-seed its deliverables. */
    public function create(int $contactId, User $actor, array $opts = []): ViralPackage
    {
        return DB::transaction(function () use ($contactId, $actor, $opts) {
            $exists = ViralPackage::where('contact_id', $contactId)->where('status', 'active')->lockForUpdate()->exists();
            abort_if($exists, 422, 'This client already has an active viral package.');

            $package = ViralPackage::create([
                'code' => ViralPackage::nextCode(),
                'contact_id' => $contactId,
                'sales_rep_id' => $actor->id,
                'title' => $opts['title'] ?? null,
                'status' => 'active',
            ]);

            // 1 article
            $this->seed($package, 'article', 1, 'Featured article');
            // 8 social posts
            for ($i = 1; $i <= VW::DEFAULT_POST_COUNT; $i++) {
                $this->seed($package, 'social_post', $i, "Social post {$i}");
            }
            // 2 reels
            for ($i = 1; $i <= VW::DEFAULT_REEL_COUNT; $i++) {
                $this->seed($package, 'reel', $i, "Reel {$i}");
            }
            // optional landing page
            if ($opts['with_landing'] ?? false) {
                $this->seed($package, 'landing_page', 1, 'Landing page');
            }

            // Assign the tech-team owner for each content type.
            $owners = $opts['owners'] ?? [];
            foreach ($owners as $kind => $userId) {
                if ($userId) {
                    $package->deliverables()->where('kind', $kind)->update(['assigned_to' => $userId]);
                }
            }
            $techTeamId = $owners['article'] ?? collect($owners)->filter()->first();
            if ($techTeamId) {
                $package->update(['tech_team_id' => $techTeamId]);
            }

            return $package;
        });
    }

    private function seed(ViralPackage $package, string $kind, int $slot, string $title): ViralDeliverable
    {
        return $package->deliverables()->create([
            'kind' => $kind, 'slot_number' => $slot, 'title' => $title, 'stage' => VW::PENDING,
        ]);
    }

    public function addDeliverable(ViralPackage $package, string $kind): ViralDeliverable
    {
        $slot = ((int) $package->deliverables()->where('kind', $kind)->max('slot_number')) + 1;

        return $this->seed($package, $kind, $slot, VW::kindLabel($kind)." {$slot}");
    }

    public function removeDeliverable(ViralDeliverable $deliverable): void
    {
        $count = ViralDeliverable::where('viral_package_id', $deliverable->viral_package_id)->where('kind', $deliverable->kind)->count();
        if (in_array($deliverable->kind, ['social_post', 'reel'], true) && $count <= 1) {
            abort(422, 'A package must keep at least one '.VW::kindLabel($deliverable->kind).'.');
        }
        $deliverable->delete();
    }

    // --- Deliverable transitions ---

    public function pickUp(ViralDeliverable $d, User $actor): ViralDeliverable
    {
        $this->ensureStage($d, [VW::PENDING]);
        $d->assigned_to = $actor->id;
        $this->transition($d, VW::IN_PROGRESS, $actor);

        return $d;
    }

    public function submit(ViralDeliverable $d, User $actor, array $data, ?UploadedFile $file = null): ViralDeliverable
    {
        // Allow submitting from in-progress, or updating content while already in review.
        $this->ensureStage($d, [VW::IN_PROGRESS, VW::REVIEW]);
        $wasInProgress = $d->stage === VW::IN_PROGRESS;
        $d->fill([
            'caption' => $data['caption'] ?? $d->caption,
            'hashtags' => $data['hashtags'] ?? $d->hashtags,
            'target_audience' => $data['target_audience'] ?? $d->target_audience,
            'landing_page_url' => $data['landing_page_url'] ?? $d->landing_page_url,
            'submitted_at' => now(),
        ]);
        if ($file) {
            $d->file_path = $file->store('viral/deliverables', 'public');
            $d->filename = $file->getClientOriginalName();
            $d->mime_type = $file->getClientMimeType();
            $d->file_size = $file->getSize();
        }
        if ($wasInProgress) {
            $this->transition($d, VW::REVIEW, $actor);
        } else {
            $d->save(); // already in review — just update the content
        }

        return $d;
    }

    public function approve(ViralDeliverable $d, User $actor): ViralDeliverable
    {
        $this->ensureStage($d, [VW::REVIEW]);
        $d->approved_at = now();
        $this->transition($d, VW::APPROVED, $actor);

        return $d;
    }

    public function requestCorrection(ViralDeliverable $d, User $actor, ?string $notes = null): ViralDeliverable
    {
        $this->ensureStage($d, [VW::REVIEW, VW::APPROVED]);
        $d->approved_at = null;
        $this->transition($d, VW::IN_PROGRESS, $actor, $notes);

        return $d;
    }

    // --- Package lifecycle ---

    public function markDelivered(ViralPackage $package, ?\Illuminate\Support\Carbon $date = null): ViralPackage
    {
        $pending = $package->deliverables()->where('stage', '!=', VW::APPROVED)->count();
        abort_if($pending > 0, 422, "All deliverables must be approved first ({$pending} still open).");

        $package->update(['status' => 'completed', 'completed_at' => $date ?? now()]);

        return $package;
    }

    /**
     * Admin reassignment: set the sales rep and the owner for each content type.
     * Owners apply to that type's non-approved deliverables (approved keep their owner).
     */
    public function reassign(ViralPackage $package, ?int $salesRepId, ?int $techTeamId, array $ownersByKind): ViralPackage
    {
        $package->update([
            'sales_rep_id' => $salesRepId,
            'tech_team_id' => $techTeamId ?? $package->tech_team_id,
        ]);

        foreach ($ownersByKind as $kind => $userId) {
            if (! $userId) {
                continue;
            }
            $package->deliverables()
                ->where('kind', $kind)
                ->where('stage', '!=', VW::APPROVED)
                ->update(['assigned_to' => $userId]);
        }

        return $package;
    }

    public function reopen(ViralPackage $package): ViralPackage
    {
        $package->update(['status' => 'active', 'completed_at' => null]);

        return $package;
    }

    // --- helpers ---

    private function transition(ViralDeliverable $d, string $to, User $actor, ?string $notes = null): void
    {
        $from = $d->stage;
        $d->stage = $to;
        $d->save();
        $d->history()->create([
            'from_stage' => $from, 'to_stage' => $to, 'changed_by' => $actor->id, 'notes' => $notes, 'changed_at' => now(),
        ]);
    }

    private function ensureStage(ViralDeliverable $d, array $allowed): void
    {
        abort_unless(in_array($d->stage, $allowed, true), 422, 'Not allowed from stage '.VW::stageLabel($d->stage).'.');
    }
}
