<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RefType;
use App\Enums\RepoAction;
use Database\Factories\RepoStateSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'deployment_id', 'repository_version_id',
    'previous_present', 'previous_ref_type', 'previous_ref_value',
    'new_present', 'new_ref_type', 'new_ref_value',
])]
class RepoStateSnapshot extends Model
{
    /** @use HasFactory<RepoStateSnapshotFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'previous_present' => 'boolean',
            'new_present' => 'boolean',
            'previous_ref_type' => RefType::class,
            'new_ref_type' => RefType::class,
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
     * Whether this snapshot can drive a rollback.
     *
     * Two cases qualify. If the checkout was present we need the ref to restore
     * it to. If it was absent, there is nothing to look up — the undo is simply
     * to remove it again — so an absent snapshot is always usable.
     */
    public function isRollbackable(): bool
    {
        return $this->previous_present === false
            || ($this->previous_ref_value !== null && $this->previous_ref_type !== null);
    }

    /**
     * What a rollback should do to this checkout.
     */
    public function rollbackAction(): RepoAction
    {
        return $this->previous_present
            ? RepoAction::Deploy
            : RepoAction::Undeploy;
    }

    /**
     * One line for the "undo point" panel.
     */
    public function summary(): string
    {
        $before = $this->previous_present
            ? ($this->previous_ref_value ?? 'unknown ref')
            : 'not deployed';

        $after = $this->new_present
            ? ($this->new_ref_value ?? 'unknown ref')
            : 'removed';

        return $before.' → '.$after;
    }
}
