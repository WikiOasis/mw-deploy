<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DeploymentPatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['deployment_id', 'patch_id', 'applied', 'applied_to_ref'])]
class DeploymentPatch extends Model
{
    /** @use HasFactory<DeploymentPatchFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['applied' => 'boolean'];
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public function patch(): BelongsTo
    {
        return $this->belongsTo(Patch::class);
    }
}
