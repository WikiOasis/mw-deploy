<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RepositoryPermissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['repository_id', 'user_id', 'role_id'])]
class RepositoryPermission extends Model
{
    /** @use HasFactory<RepositoryPermissionFactory> */
    use HasFactory;

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
