<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadType;
use App\Support\Notifier;
use Illuminate\Http\Request;

/**
 * Public web/Elementor lead intake. Deduplicates on normalized phone/email so
 * repeat submissions attach to the same contact.
 *
 * NOTE: this endpoint should be protected with a shared secret / signature and
 * rate-limited before going to production (tracked for the integration pass).
 */
class LeadIntakeController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:190'],
            'contact_person' => ['nullable', 'string', 'max:190'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'industry' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'lead_type' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:5000'],
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
                'city' => $data['city'] ?? null,
                'source' => 'web',
            ]);
        }

        $typeId = null;
        if (! empty($data['lead_type'])) {
            $typeId = LeadType::firstOrCreate(['name' => $data['lead_type']])->id;
        }

        $lead = Lead::create([
            'contact_id' => $contact->id,
            'lead_type_id' => $typeId,
            'title' => $data['lead_type'] ?? 'Website enquiry',
            'pipeline_stage' => 'intake',
            'status' => 'active',
            'source' => 'web',
            'notes' => $data['message'] ?? null,
            'last_activity_at' => now(),
        ]);

        Notifier::toPermission('leads.view.all', [
            'type' => 'lead',
            'event' => 'created',
            'title' => 'New web lead',
            'message' => $contact->business_name.' — '.$lead->title,
            'url' => '/leads/'.$lead->id,
            'icon' => 'lead',
        ]);

        return response()->json(['message' => 'Lead received', 'lead_id' => $lead->id], 201);
    }
}
