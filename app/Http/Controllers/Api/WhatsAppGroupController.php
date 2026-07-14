<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\WhatsappGroup;
use App\Models\WhatsappGroupMember;
use Illuminate\Http\Request;

class WhatsAppGroupController extends Controller
{
    public function index()
    {
        $groups = WhatsappGroup::withCount('members')->with('creator')->latest()->get()
            ->map(fn (WhatsappGroup $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'description' => $g->description,
                'color' => $g->color,
                'is_active' => $g->is_active,
                'members_count' => $g->members_count,
                'created_by' => $g->creator?->name,
                'created_at' => $g->created_at->toIso8601String(),
            ]);

        return response()->json(['data' => $groups]);
    }

    public function show(WhatsappGroup $group)
    {
        $group->load(['members' => fn ($q) => $q->latest()]);

        return response()->json(['data' => [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'color' => $group->color,
            'is_active' => $group->is_active,
            'members' => $group->members->map(fn (WhatsappGroupMember $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'phone' => $m->phone,
                'contact_id' => $m->contact_id,
            ]),
        ]]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);
        $data['created_by'] = $request->user()->id;
        $group = WhatsappGroup::create($data);

        return response()->json(['data' => ['id' => $group->id, 'name' => $group->name]], 201);
    }

    public function update(Request $request, WhatsappGroup $group)
    {
        $group->update($request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
        ]));

        return response()->json(['data' => ['id' => $group->id]]);
    }

    public function destroy(WhatsappGroup $group)
    {
        $group->delete();

        return response()->json(['message' => 'Group deleted.']);
    }

    /** Add members from picked contacts and/or raw {name, phone} rows. */
    public function addMembers(Request $request, WhatsappGroup $group)
    {
        $data = $request->validate([
            'contact_ids' => ['nullable', 'array'],
            'contact_ids.*' => ['integer', 'exists:contacts,id'],
            'members' => ['nullable', 'array'],
            'members.*.name' => ['nullable', 'string', 'max:120'],
            'members.*.phone' => ['required_with:members', 'string', 'max:40'],
        ]);

        $added = 0;
        $upsert = function (?string $name, ?string $phone, ?int $contactId) use ($group, &$added) {
            $clean = preg_replace('/\D+/', '', (string) $phone);
            if (! $clean) {
                return;
            }
            $member = WhatsappGroupMember::firstOrNew(['group_id' => $group->id, 'phone' => $clean]);
            $member->name = $name ?: $member->name;
            $member->contact_id = $contactId ?: $member->contact_id;
            if (! $member->exists) {
                $added++;
            }
            $member->save();
        };

        if (! empty($data['contact_ids'])) {
            Contact::whereIn('id', $data['contact_ids'])->get()->each(
                fn (Contact $c) => $upsert($c->business_name ?? $c->contact_person, $c->phone, $c->id)
            );
        }
        foreach ($data['members'] ?? [] as $m) {
            $upsert($m['name'] ?? null, $m['phone'], null);
        }

        return response()->json(['message' => "Added {$added} member(s).", 'added' => $added]);
    }

    public function removeMember(WhatsappGroupMember $member)
    {
        $member->delete();

        return response()->json(['message' => 'Removed.']);
    }

    /** Contacts with a phone number — for the "add from contacts" picker. */
    public function contactOptions(Request $request)
    {
        $q = Contact::whereNotNull('phone')->where('phone', '!=', '');
        if ($term = trim((string) $request->input('search'))) {
            $q->where(fn ($w) => $w->where('business_name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"));
        }
        $contacts = $q->orderBy('business_name')->limit(50)->get()
            ->map(fn (Contact $c) => ['id' => $c->id, 'name' => $c->business_name ?? $c->contact_person, 'phone' => $c->phone]);

        return response()->json(['data' => $contacts]);
    }
}
