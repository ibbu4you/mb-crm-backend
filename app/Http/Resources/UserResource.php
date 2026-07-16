<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar_url' => $this->avatar_url,
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at,
            'created_at' => $this->created_at,
            'roles' => $this->getRoleNames(),
            // effective = what the user can actually do (denials removed), so the
            // frontend gate matches the backend Gate
            'permissions' => $this->effectivePermissions(),
            'denied_permissions' => $this->denied_permissions ?? [],
        ];
    }
}
