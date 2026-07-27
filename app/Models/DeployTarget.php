<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TargetRole;
use Database\Factories\DeployTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'hostname', 'role', 'haproxy_backend', 'haproxy_server_name',
    'canary_vhost', 'active', 'sort_order',
])]
class DeployTarget extends Model
{
    /** @use HasFactory<DeployTargetFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'role' => TargetRole::class,
            'active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    public function scopeRole(Builder $query, TargetRole $role): void
    {
        $query->where('role', $role->value);
    }

    /**
     * Label HAProxy knows this server by, falling back to the minion id.
     */
    public function haproxyServerName(): string
    {
        return $this->haproxy_server_name ?: $this->hostname;
    }

    public function canaryVhost(): string
    {
        return $this->canary_vhost ?: (string) config('mwdeploy.rollout.canary_vhost');
    }
}
