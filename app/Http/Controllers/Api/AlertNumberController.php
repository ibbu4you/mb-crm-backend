<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappNumber;
use Illuminate\Http\Request;

class AlertNumberController extends Controller
{
    public function index()
    {
        return response()->json(['data' => WhatsappNumber::latest()->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40'],
        ]);
        $data['phone'] = preg_replace('/\D+/', '', $data['phone']);

        return response()->json(['data' => WhatsappNumber::create($data)], 201);
    }

    public function update(Request $request, WhatsappNumber $whatsappNumber)
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
            'phone' => ['sometimes', 'string', 'max:40'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['phone'])) {
            $data['phone'] = preg_replace('/\D+/', '', $data['phone']);
        }
        $whatsappNumber->update($data);

        return response()->json(['data' => $whatsappNumber]);
    }

    public function destroy(WhatsappNumber $whatsappNumber)
    {
        $whatsappNumber->delete();

        return response()->json(['message' => 'Removed.']);
    }
}
