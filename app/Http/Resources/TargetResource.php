<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DeployTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DeployTarget
 */
final class TargetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            // Must equal the Salt minion id exactly: this string is what gets
            // passed as the Salt target.
            'hostname' => $this->hostname,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'haproxy_backend' => $this->haproxy_backend,
            'haproxy_server_name' => $this->haproxy_server_name,
            'haproxy_effective_name' => $this->haproxyServerName(),
            'canary_vhost' => $this->canary_vhost,
            'canary_effective_vhost' => $this->canaryVhost(),
            'active' => $this->active,
            'sort_order' => $this->sort_order,
        ];
    }
}
