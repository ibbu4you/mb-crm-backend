<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OfficeLocation;
use Illuminate\Http\Request;

class OfficeLocationController extends Controller
{
    public function index()
    {
        return response()->json(['data' => OfficeLocation::latest()->get()]);
    }

    public function store(Request $request)
    {
        return response()->json(['data' => OfficeLocation::create($this->validated($request))], 201);
    }

    public function update(Request $request, OfficeLocation $officeLocation)
    {
        $officeLocation->update($this->validated($request, true));

        return response()->json(['data' => $officeLocation]);
    }

    public function destroy(OfficeLocation $officeLocation)
    {
        $officeLocation->delete();

        return response()->json(['message' => 'Removed.']);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:120'],
            'lat' => [$partial ? 'sometimes' : 'required', 'numeric', 'between:-90,90'],
            'lng' => [$partial ? 'sometimes' : 'required', 'numeric', 'between:-180,180'],
            'radius_m' => ['nullable', 'integer', 'min:20', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
