<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $q = User::query()->with('roles');

        if ($search = $request->string('search')->trim()->value()) {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $q->where('is_active', $request->string('status')->value() === 'active');
        }

        if ($role = $request->string('role')->value()) {
            $q->whereHas('roles', fn ($r) => $r->where('name', $role));
        }

        $q->orderByDesc('created_at');

        return UserResource::collection($q->paginate($request->integer('per_page', 15)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => ['nullable', 'string', 'min:8'],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => ['array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
            'denied_permissions' => ['array'],
            'denied_permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        $generated = null;
        if (empty($data['password'])) {
            $generated = Str::password(12);
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'password' => Hash::make($data['password'] ?? $generated),
        ]);

        $user->syncRoles($data['roles'] ?? []);
        $user->syncPermissions($data['permissions'] ?? []);
        $user->update(['denied_permissions' => array_values(array_unique($data['denied_permissions'] ?? []))]);

        return (new UserResource($user->load('roles')))
            ->additional(['generated_password' => $generated])
            ->response()
            ->setStatusCode(201);
    }

    public function show(User $employee)
    {
        return new UserResource($employee->load('roles', 'permissions'));
    }

    public function update(Request $request, User $employee)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', 'max:190', Rule::unique('users', 'email')->ignore($employee->id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => ['nullable', 'string', 'min:8'],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
            'denied_permissions' => ['sometimes', 'array'],
            'denied_permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        $employee->fill(collect($data)->only(['name', 'email', 'phone', 'is_active'])->toArray());
        if (! empty($data['password'])) {
            $employee->password = Hash::make($data['password']);
        }
        $employee->save();

        if (array_key_exists('roles', $data)) {
            $employee->syncRoles($data['roles']);
        }
        if (array_key_exists('permissions', $data)) {
            $employee->syncPermissions($data['permissions']);
        }
        if (array_key_exists('denied_permissions', $data)) {
            $employee->update(['denied_permissions' => array_values(array_unique($data['denied_permissions']))]);
        }

        return new UserResource($employee->fresh()->load('roles', 'permissions'));
    }

    public function toggleActive(User $employee)
    {
        $employee->update(['is_active' => ! $employee->is_active]);

        return new UserResource($employee->load('roles'));
    }

    public function destroy(Request $request, User $employee)
    {
        if ($employee->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }
        if ($employee->hasRole(Roles::SUPER_ADMIN) && User::role(Roles::SUPER_ADMIN)->count() <= 1) {
            return response()->json(['message' => 'Cannot delete the last Administrator.'], 422);
        }

        $employee->delete();

        return response()->json(['message' => 'Employee deleted.']);
    }
}
