<?php

namespace App\Http\Resources;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var UserRole|string|null $role */
        $role = $this->resource->role;

        $roleEnum = $role instanceof UserRole ? $role : null;

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $roleEnum?->value ?? (string) $role,
            'role_label' => $roleEnum?->label() ?? (string) $role,
            'invite_expires_at' => $this->invite_expires_at,
            'archived_at' => $this->archived_at,
            'created_at' => $this->created_at,
            'is_current_user' => $request->user()?->id === $this->id,
        ];
    }
}
