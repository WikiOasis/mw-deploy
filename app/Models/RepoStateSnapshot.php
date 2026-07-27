<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RefType;
use Database\Factories\RepoStateSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'deployment_id', 'repository_id',
    'previous_ref_type', 'previous_ref_value',
    'new_ref_type', 'new_ref_value',
])]
class RepoStateSnapshot extends Model
{
    /** @use HasFactory<RepoStateSnapshotFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'previous_ref_type' => RefType::class,
            'new_ref_type' => RefType::class,
        ];
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * A snapshot is only usable as a rollback target if we actually managed to
     * read the previous HEAD before changing anything.
     */
    public function isRollbackable(): bool
    {
        return $this->previous_ref_value !== null && $this->previous_ref_type !== null;
    }
}
