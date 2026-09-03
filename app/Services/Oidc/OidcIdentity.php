<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use App\Models\OidcSettings;
use Illuminate\Support\Arr;

/**
 * What the IdP said about the person signing in, once the ID token and (where
 * needed) userinfo have been merged and read.
 *
 * Claim names are not a settled thing across IdPs, so the reading happens here,
 * in one place, and the rest of the flow deals in a subject, a name, an email and
 * a list of groups.
 */
final class OidcIdentity
{
    /**
     * @param  list<string>  $groups
     */
    private function __construct(
        public readonly string $subject,
        public readonly string $email,
        public readonly bool $emailVerified,
        public readonly string $name,
        public readonly array $groups,
    ) {}

    /**
     * @param  array<string, mixed>  $claims
     */
    public static function fromClaims(array $claims, OidcSettings $settings): self
    {
        $email = (string) (Arr::get($claims, 'email') ?? '');

        return new self(
            subject: (string) Arr::get($claims, 'sub'),
            email: mb_strtolower(trim($email)),
            emailVerified: filter_var(Arr::get($claims, 'email_verified'), FILTER_VALIDATE_BOOLEAN),
            name: self::readName($claims, $email),
            groups: self::readGroups($claims, (string) $settings->groups_claim),
        );
    }

    /**
     * Group membership, from whichever claim this install's IdP puts it in.
     *
     * Dot notation is accepted so a nested claim — Entra's
     * `resource_access.console.roles`, for instance — can be named without this
     * needing to know anything about the IdP. Both shapes real providers use are
     * handled: a JSON array, and a single space-separated string.
     *
     * @param  array<string, mixed>  $claims
     * @return list<string>
     */
    private static function readGroups(array $claims, string $claimName): array
    {
        $raw = Arr::get($claims, $claimName === '' ? 'groups' : $claimName);

        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw, flags: PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $groups = [];

        foreach ($raw as $entry) {
            /*
             * Some providers send objects rather than strings — a Keycloak
             * client role, say. Take the obvious name field and ignore anything
             * that is not nameable at all, rather than stringifying an array
             * into a group nobody can map.
             */
            $name = match (true) {
                is_string($entry) => $entry,
                is_array($entry) => (string) ($entry['name'] ?? $entry['id'] ?? ''),
                default => '',
            };

            $name = trim($name);

            if ($name !== '') {
                $groups[] = $name;
            }
        }

        return array_values(array_unique($groups));
    }

    /**
     * A display name, falling back through the claims an IdP might actually
     * populate before settling for the local part of the email — which is at
     * least recognisable, unlike a UUID subject.
     *
     * @param  array<string, mixed>  $claims
     */
    private static function readName(array $claims, string $email): string
    {
        foreach (['name', 'preferred_username', 'nickname'] as $claim) {
            $value = Arr::get($claims, $claim);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $given = trim((string) (Arr::get($claims, 'given_name') ?? '').' '.(string) (Arr::get($claims, 'family_name') ?? ''));

        if ($given !== '') {
            return $given;
        }

        return $email === '' ? 'Unnamed account' : Arr::first(explode('@', $email));
    }
}
