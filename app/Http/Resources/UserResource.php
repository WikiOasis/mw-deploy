<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'name' => $this->name,
            'email' => $this->email,
            'roles' => $this->whenLoaded('roles', fn (): array => $this->roles
                ->map(fn (Role $role): array => [
                    'id' => $role->getKey(),
                    'name' => $role->name,
                ])->values()->all()),
            'role_ids' => $this->whenLoaded('roles', fn (): array => $this->roles->modelKeys()),
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            'two_factor_required' => $this->requiresTwoFactor(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
