<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeadType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadTypeController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => LeadType::orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('lead_types', 'name')],
            'color' => ['nullable', 'string', 'max:20'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        return response()->json(['data' => LeadType::create($data)], 201);
    }

    public function update(Request $request, LeadType $leadType)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120', Rule::unique('lead_types', 'name')->ignore($leadType->id)],
            'color' => ['nullable', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);
        $leadType->update($data);

        return response()->json(['data' => $leadType]);
    }

    public function destroy(LeadType $leadType)
    {
        $leadType->delete();

        return response()->json(['message' => 'Lead type deleted.']);
    }
}
