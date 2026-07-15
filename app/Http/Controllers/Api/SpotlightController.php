<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadType;
use App\Support\Notifier;
use Illuminate\Http\Request;

/**
 * Public "Get Featured on Malayznbeat" (Free Spotlight) landing-page intake.
 * Creates a Free Spotlight lead, folding the campaign-specific answers into the
 * lead's notes + meta. Deduplicates on normalized phone/email. Rate-limited at
 * the route. No auth — this is a public marketing funnel.
 */
class SpotlightController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:190'],
            'contact_person' => ['nullable', 'string', 'max:190'],
            'position' => ['nullable', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'],
            'industry' => ['nullable', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:190'],
            'story' => ['nullable', 'string', 'max:5000'],
            'unique_story' => ['nullable', 'string', 'max:5000'],
            'links' => ['nullable', 'string', 'max:1000'],
            'interview_mode' => ['nullable', 'string', 'max:60'],
            'language' => ['nullable', 'string', 'max:60'],
            'preferred_time' => ['nullable', 'string', 'max:190'],
            'comments' => ['nullable', 'string', 'max:5000'],
            'referral_name' => ['nullable', 'string', 'max:190'],
            'consent_coverage' => ['nullable', 'boolean'],
            'consent_contact' => ['nullable', 'boolean'],
            'attachment' => ['nullable', 'file', 'max:8192', 'mimes:jpg,jpeg,png,webp,pdf'],
        ]);

        $phone = Contact::normalizePhone($data['phone'] ?? null);
        $contact = Contact::query()
            ->when($phone, fn ($q) => $q->orWhere('phone_normalized', $phone))
            ->when($data['email'] ?? null, fn ($q) => $q->orWhere('email', $data['email']))
            ->first();

        if (! $contact) {
            $contact = Contact::create([
                'business_name' => $data['business_name'],
                'contact_person' => $data['contact_person'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'industry' => $data['industry'] ?? null,
                'city' => $data['location'] ?? null,
                'source' => 'web',
            ]);
        }

        $attachmentUrl = null;
        if ($request->hasFile('attachment')) {
            $attachmentUrl = asset('storage/'.$request->file('attachment')->store('spotlight', 'public'));
        }

        $typeId = LeadType::firstOrCreate(['name' => 'Free Spotlight'])->id;

        // Nested under `form` because that is the shape LeadDetails::fromMeta()
        // reads — it renders the lead drawer's "Additional details" panel. A flat
        // array here is stored but never displayed. Keys are the labels the team sees.
        $meta = [
            'campaign' => 'free_spotlight',
            'form' => array_filter([
                'Position' => $data['position'] ?? null,
                'Location (City, State)' => $data['location'] ?? null,
                'Tell Us About Your Business / Story' => $data['story'] ?? null,
                'What Makes Your Story Unique or Inspiring?' => $data['unique_story'] ?? null,
                'Website / Social Media Links' => $data['links'] ?? null,
                'Preferred Interview Mode' => $data['interview_mode'] ?? null,
                'Preferred Language' => $data['language'] ?? null,
                'Preferred Time Slot' => $data['preferred_time'] ?? null,
                'Comments' => $data['comments'] ?? null,
                'Referral Name' => $data['referral_name'] ?? null,
                'Attachment' => $attachmentUrl,
                'Consent — article coverage' => ! empty($data['consent_coverage']) ? 'Yes' : null,
                'Consent — editorial contact' => ! empty($data['consent_contact']) ? 'Yes' : null,
            ], fn ($v) => $v !== null && $v !== ''),
        ];

        $lead = Lead::create([
            'contact_id' => $contact->id,
            'lead_type_id' => $typeId,
            'title' => 'Free Spotlight — '.$data['business_name'],
            'pipeline_stage' => 'intake',
            'status' => 'active',
            'source' => 'web',
            'notes' => $this->buildNotes($data, $attachmentUrl),
            'meta' => $meta,
            'last_activity_at' => now(),
        ]);

        Notifier::toPermission('leads.view.all', [
            'type' => 'lead',
            'event' => 'created',
            'title' => 'New Free Spotlight registration',
            'message' => $contact->business_name.' · '.($data['phone'] ?? $data['email'] ?? ''),
            'url' => '/leads/'.$lead->id,
            'icon' => 'lead',
        ]);

        return response()->json([
            'message' => 'Thank you! Your registration was received — our team will reach out within 3 days.',
            'lead_id' => $lead->id,
        ], 201);
    }

    private function buildNotes(array $d, ?string $attachmentUrl): string
    {
        $rows = [
            'Position' => $d['position'] ?? null,
            'Location' => $d['location'] ?? null,
            'About the business' => $d['story'] ?? null,
            'What makes it unique' => $d['unique_story'] ?? null,
            'Website / Social links' => $d['links'] ?? null,
            'Preferred interview mode' => $d['interview_mode'] ?? null,
            'Language' => $d['language'] ?? null,
            'Preferred time slot' => $d['preferred_time'] ?? null,
            'Comments' => $d['comments'] ?? null,
            'Referred by' => $d['referral_name'] ?? null,
            'Attachment' => $attachmentUrl,
        ];

        return collect($rows)
            ->filter(fn ($v) => ! empty($v))
            ->map(fn ($v, $k) => "{$k}: {$v}")
            ->implode("\n");
    }
}
