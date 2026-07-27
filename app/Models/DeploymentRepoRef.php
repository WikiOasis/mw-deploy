<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RefType;
use App\Enums\RepoAction;
use Database\Factories\DeploymentRepoRefFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['deployment_id', 'repository_version_id', 'action', 'ref_type', 'ref_value'])]
class DeploymentRepoRef extends Model
{
    /** @use HasFactory<DeploymentRepoRefFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'action' => RepoAction::class,
            'ref_type' => RefType::class,
        ];
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public function repositoryVersion(): BelongsTo
    {
        return $this->belongsTo(RepositoryVersion::class);
    }

    /**
     * The logical repository behind this line item.
     *
     * Deliberately *not* named repository(): Eloquent treats a method with a
     * relation-shaped name as a relation, and one that returns null instead of a
     * Relation blows up any eager load that mentions it.
     */
    public function logicalRepository(): ?Repository
    {
        return $this->repositoryVersion?->repository;
    }

    public function isUndeploy(): bool
    {
        return $this->action === RepoAction::Undeploy;
    }

    public function shortRef(): string
    {
        if ($this->ref_value === null) {
            return '—';
        }

        return $this->ref_type === RefType::Commit
            ? substr($this->ref_value, 0, 10)
            : $this->ref_value;
    }

    /**
     * "Echo (1.45) → REL1_45" or "Echo (1.45) — removed".
     */
    public function summary(): string
    {
        $name = $this->repositoryVersion?->displayName() ?? 'deleted checkout';

        return $this->isUndeploy()
            ? $name.' — removed'
            : $name.' → '.$this->shortRef();
    }
}
