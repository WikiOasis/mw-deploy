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
            /*
             * Whether this account signs in through the identity provider. Worth
             * showing on the access screen: an account whose roles come from an
             * IdP group is one whose roles will come back after being edited
             * here, and that is better seen than discovered.
             */
            'single_sign_on' => $this->oidc_subject !== null,
            'password_set' => filled($this->password),
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            'two_factor_required' => $this->requiresTwoFactor(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
