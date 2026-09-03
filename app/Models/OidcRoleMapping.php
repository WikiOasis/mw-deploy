<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * One "members of this IdP group hold this console role" rule.
 *
 * The mapping is deliberately to a role rather than to permissions: roles are
 * already the unit access is granted in here, so an IdP group lands someone in
 * the same bucket an administrator would have put them in by hand, and the
 * access screen keeps telling the whole truth about what they can do.
 */
#[Fillable(['group', 'role_id'])]
final class OidcRoleMapping extends Model
{
    protected $table = 'oidc_role_mappings';

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Role ids granted by the given IdP groups.
     *
     * Group names are compared case-insensitively: IdPs are inconsistent about
     * the case they hand back, and "the group is called Ops not ops" is a
     * miserable thing to debug from an access-denied page.
     *
     * @param  list<string>  $groups
     * @return list<int>
     */
    public static function rolesFor(array $groups): array
    {
        if ($groups === []) {
            return [];
        }

        $wanted = array_map(mb_strtolower(...), $groups);

        return self::query()
            ->get(['group', 'role_id'])
            ->filter(fn (self $mapping): bool => in_array(mb_strtolower((string) $mapping->group), $wanted, true))
            ->pluck('role_id')
            ->unique()
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Every mapping, with its role, for the settings screen.
     *
     * @return Collection<int, self>
     */
    public static function listed(): Collection
    {
        return self::query()->with('role')->orderBy('group')->get();
    }
}
