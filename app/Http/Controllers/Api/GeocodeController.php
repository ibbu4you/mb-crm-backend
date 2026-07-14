<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GeocodingService;
use Illuminate\Http\Request;

class GeocodeController extends Controller
{
    public function __invoke(Request $request, GeocodingService $geo)
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return response()->json(['address' => $geo->reverse($data['lat'], $data['lng'])]);
    }
}
