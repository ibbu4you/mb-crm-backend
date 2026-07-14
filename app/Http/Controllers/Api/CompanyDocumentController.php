<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyDocument;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanyDocumentController extends Controller
{
    private const CATEGORIES = ['profile', 'rate_card', 'deck', 'other'];

    public function index(Request $request)
    {
        $q = CompanyDocument::query();
        if (! $request->user()->can('documents.manage')) {
            $q->where('is_active', true);
        }

        return response()->json([
            'data' => $q->orderBy('sort_order')->latest()->get()->map(fn ($d) => [
                'id' => $d->id, 'title' => $d->title, 'category' => $d->category, 'original_name' => $d->original_name,
                'size' => $d->size, 'is_active' => $d->is_active, 'url' => $d->file_url,
                'created_at' => $d->created_at->toIso8601String(),
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'category' => ['nullable', Rule::in(self::CATEGORIES)],
            'file' => ['required', 'file', 'mimes:pdf,doc,docx,ppt,pptx', 'max:20480'],
        ]);
        $file = $request->file('file');
        $doc = CompanyDocument::create([
            'title' => $data['title'],
            'category' => $data['category'] ?? 'other',
            'file_path' => $file->store('company-docs', 'public'),
            'original_name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json(['data' => [
            'id' => $doc->id, 'title' => $doc->title, 'category' => $doc->category,
            'original_name' => $doc->original_name, 'size' => $doc->size, 'url' => $doc->file_url,
            'is_active' => true, 'created_at' => $doc->created_at->toIso8601String(),
        ]], 201);
    }

    public function update(Request $request, CompanyDocument $companyDocument)
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:190'],
            'category' => ['sometimes', Rule::in(self::CATEGORIES)],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $companyDocument->update($data);

        return response()->json(['data' => $companyDocument]);
    }

    public function destroy(CompanyDocument $companyDocument)
    {
        $companyDocument->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
