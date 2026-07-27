<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RefType;
use Database\Factories\DeploymentRepoRefFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['deployment_id', 'repository_id', 'ref_type', 'ref_value'])]
class DeploymentRepoRef extends Model
{
    /** @use HasFactory<DeploymentRepoRefFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['ref_type' => RefType::class];
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function shortRef(): string
    {
        return $this->ref_type === RefType::Commit
            ? substr($this->ref_value, 0, 10)
            : $this->ref_value;
    }
}
