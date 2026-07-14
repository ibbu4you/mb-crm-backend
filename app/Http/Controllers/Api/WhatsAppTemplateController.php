<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappTemplate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WhatsAppTemplateController extends Controller
{
    private const CATEGORIES = ['marketing', 'utility', 'authentication'];
    private const STATUSES = ['draft', 'pending', 'approved', 'rejected'];

    public function index(Request $request)
    {
        $q = WhatsappTemplate::with('creator');
        if ($cat = $request->input('category')) {
            $q->where('category', $cat);
        }
        if ($term = trim((string) $request->input('search'))) {
            $q->where('name', 'like', "%{$term}%");
        }

        return response()->json(['data' => $q->latest()->get()->map(fn ($t) => $this->row($t))]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $data['created_by'] = $request->user()->id;
        $template = WhatsappTemplate::create($data);

        return response()->json(['data' => $this->row($template->fresh('creator'))], 201);
    }

    public function update(Request $request, WhatsappTemplate $template)
    {
        $template->update($this->validateData($request));

        return response()->json(['data' => $this->row($template->fresh('creator'))]);
    }

    public function destroy(WhatsappTemplate $template)
    {
        $template->delete();

        return response()->json(['message' => 'Template deleted.']);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'language' => ['nullable', 'string', 'max:10'],
            'header' => ['nullable', 'string', 'max:190'],
            'body' => ['required', 'string', 'max:4096'],
            'footer' => ['nullable', 'string', 'max:190'],
            'buttons' => ['nullable', 'array', 'max:3'],
            'buttons.*.type' => ['required_with:buttons', Rule::in(['url', 'phone', 'quick_reply'])],
            'buttons.*.text' => ['required_with:buttons', 'string', 'max:40'],
            'buttons.*.value' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
        ]);
    }

    private function row(WhatsappTemplate $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'category' => $t->category,
            'language' => $t->language,
            'header' => $t->header,
            'body' => $t->body,
            'footer' => $t->footer,
            'buttons' => $t->buttons ?? [],
            'status' => $t->status,
            'variables' => $t->variable_count,
            'created_by' => $t->creator?->name,
            'created_at' => $t->created_at->toIso8601String(),
        ];
    }
}
