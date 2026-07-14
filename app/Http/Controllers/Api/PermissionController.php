<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Permissions;

class PermissionController extends Controller
{
    /** Returns the permission catalog grouped by module (drives the matrix UI). */
    public function index()
    {
        $groups = [];
        foreach (Permissions::catalog() as $key => $group) {
            $groups[] = [
                'group' => $key,
                'label' => $group['label'],
                'permissions' => collect($group['permissions'])
                    ->map(fn ($label, $name) => ['name' => $name, 'label' => $label])
                    ->values(),
            ];
        }

        return response()->json(['data' => $groups]);
    }
}
