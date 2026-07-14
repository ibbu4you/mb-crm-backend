<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Roles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index()
    {
        // Count assignees straight from the pivot (avoids spatie's morph-relation
        // guard resolution failing inside a withCount subquery).
        $counts = DB::table(config('permission.table_names.model_has_roles'))
            ->select('role_id', DB::raw('count(*) as c'))
            ->groupBy('role_id')
            ->pluck('c', 'role_id');

        $roles = Role::with('permissions:id,name')->orderBy('name')->get()
            ->map(fn (Role $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'users_count' => (int) ($counts[$r->id] ?? 0),
                'is_system' => $r->name === Roles::SUPER_ADMIN,
                'permissions' => $r->permissions->pluck('name'),
            ]);

        return response()->json(['data' => $roles]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('roles', 'name')],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
        $role->syncPermissions($data['permissions'] ?? []);

        return response()->json(['data' => $role->load('permissions:id,name')], 201);
    }

    public function update(Request $request, Role $role)
    {
        if ($role->name === Roles::SUPER_ADMIN) {
            return response()->json(['message' => 'The Administrator role cannot be modified.'], 422);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60', Rule::unique('roles', 'name')->ignore($role->id)],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        if (isset($data['name'])) {
            $role->update(['name' => $data['name']]);
        }
        if (array_key_exists('permissions', $data)) {
            $role->syncPermissions($data['permissions']);
        }

        return response()->json(['data' => $role->fresh()->load('permissions:id,name')]);
    }

    public function destroy(Role $role)
    {
        if (in_array($role->name, [Roles::SUPER_ADMIN, 'Admin'], true)) {
            return response()->json(['message' => 'This role is protected and cannot be deleted.'], 422);
        }
        $role->delete();

        return response()->json(['message' => 'Role deleted.']);
    }
}
