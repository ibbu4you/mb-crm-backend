<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContactResource;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContactController extends Controller
{
    public function index(Request $request)
    {
        $q = Contact::query()->with('owner')->withCount(['leads', 'articles', 'viralPackages']);

        if (! $request->user()->can('contacts.view.all')) {
            $q->where('owner_id', $request->user()->id);
        }

        if ($search = trim((string) $request->input('search'))) {
            $digits = preg_replace('/\D+/', '', $search);
            $q->where(function ($w) use ($search, $digits) {
                $w->where('business_name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
                if ($digits) {
                    $w->orWhere('phone_normalized', 'like', "%{$digits}%");
                }
            });
        }

        if ($source = $request->input('source')) {
            $q->where('source', $source);
        }

        return ContactResource::collection($q->latest()->paginate($request->integer('per_page', 20)));
    }

    public function stats(Request $request)
    {
        $base = fn () => Contact::query()->when(
            ! $request->user()->can('contacts.view.all'),
            fn ($q) => $q->where('owner_id', $request->user()->id),
        );

        $bySource = (clone $base())->selectRaw('source, count(*) as c')->groupBy('source')->pluck('c', 'source');

        return response()->json([
            'total' => (clone $base())->count(),
            'with_leads' => (clone $base())->has('leads')->count(),
            'articles' => (clone $base())->withCount('articles')->get()->sum('articles_count'),
            'viral_packages' => (clone $base())->withCount('viralPackages')->get()->sum('viral_packages_count'),
            'by_source' => $bySource,
        ]);
    }

    /** Duplicate detection by normalized phone / email. */
    public function duplicates(Request $request)
    {
        $phone = Contact::normalizePhone($request->input('phone'));
        $email = $request->input('email');

        $matches = Contact::query()
            ->when($phone, fn ($q) => $q->orWhere('phone_normalized', $phone))
            ->when($email, fn ($q) => $q->orWhere('email', $email))
            ->limit(5)
            ->get();

        return ContactResource::collection($matches);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $data['created_by'] = $request->user()->id;
        $data['owner_id'] ??= $request->user()->id;

        $contact = Contact::create($data);

        return (new ContactResource($contact->load('owner')))->response()->setStatusCode(201);
    }

    public function show(Contact $contact)
    {
        return new ContactResource($contact->load(['owner', 'leads.type', 'leads.owner']));
    }

    public function update(Request $request, Contact $contact)
    {
        $contact->update($this->validateData($request, $contact));

        return new ContactResource($contact->load('owner'));
    }

    public function destroy(Contact $contact)
    {
        $contact->delete();

        return response()->json(['message' => 'Contact deleted.']);
    }

    private function validateData(Request $request, ?Contact $contact = null): array
    {
        return $request->validate([
            'business_name' => [$contact ? 'sometimes' : 'required', 'string', 'max:190'],
            'contact_person' => ['nullable', 'string', 'max:190'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'industry' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', Rule::in(['whatsapp', 'web', 'field', 'manual', 'referral'])],
            'owner_id' => ['nullable', 'exists:users,id'],
            'notes' => ['nullable', 'string'],
        ]);
    }
}
