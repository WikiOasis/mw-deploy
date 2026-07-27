<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'name', 'description', 'target_repo_id', 'target_path', 'file_path',
    'original_filename', 'format', 'active', 'created_by',
    'last_checked_at', 'last_check_ok', 'last_check_detail',
])]
class Patch extends Model
{
    /** @use HasFactory<PatchFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'last_check_ok' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }

    public function targetRepository(): BelongsTo
    {
        return $this->belongsTo(Repository::class, 'target_repo_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    public function isFreeform(): bool
    {
        return $this->target_repo_id === null;
    }

    /**
     * Absolute path of the patch file as the shim sees it on the minion.
     */
    public function shimPatchPath(): string
    {
        return rtrim((string) config('mwdeploy.paths.patches'), '/').'/'.ltrim(basename($this->file_path), '/');
    }

    /**
     * Absolute directory the patch is applied in, inside the staging tree.
     */
    public function stagingTargetPath(): string
    {
        return rtrim((string) config('mwdeploy.paths.staging'), '/').'/'.ltrim($this->target_path, '/');
    }
}
