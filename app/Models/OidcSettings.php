<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * How this install's single sign-on is configured.
 *
 * One row, read on the sign-in page and on every callback, written only from the
 * settings screen. `current()` returns the row whether or not it exists yet, so
 * nothing downstream has to cope with a null: a fresh install has SSO disabled
 * and every field empty, which is exactly what an unconfigured provider is.
 *
 * The endpoints are stored rather than discovered per request. Discovery is a
 * button on the settings screen, not something sign-in depends on being up.
 */
#[Fillable([
    'enabled', 'label', 'discovery_url', 'issuer', 'client_id', 'client_secret',
    'authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'jwks_uri',
    'end_session_endpoint', 'scopes', 'groups_claim', 'create_users', 'sync_roles',
    'allowed_groups', 'discovered_at',
])]
#[Hidden(['client_secret'])]
final class OidcSettings extends Model
{
    protected $table = 'oidc_settings';

    /**
     * The unconfigured state, so `current()` can answer without writing a row.
     * Kept in step with the column defaults in the migration.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'enabled' => false,
        'label' => 'single sign-on',
        'scopes' => 'openid profile email groups',
        'groups_claim' => 'groups',
        'create_users' => true,
        'sync_roles' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'create_users' => 'boolean',
            'sync_roles' => 'boolean',
            'allowed_groups' => 'array',
            // Laravel's encrypted cast, so the secret is unreadable in a dump
            // and rotating APP_KEY is the only thing that can lose it.
            'client_secret' => 'encrypted',
            'discovered_at' => 'datetime',
        ];
    }

    /**
     * The one row, or an unsaved instance carrying the defaults.
     *
     * Deliberately does not create anything: this is read while rendering the
     * sign-in page, and a GET of a login form is no place for a write. The
     * settings screen is what saves the row.
     *
     * Not memoised either — the settings screen writes and re-reads within one
     * request, and a stale copy there would show the administrator the
     * configuration they just replaced.
     */
    public static function current(): self
    {
        return self::query()->firstOrNew([]);
    }

    /**
     * Whether sign-in can actually be attempted: switched on, and configured
     * enough to build an authorisation request from.
     */
    public function isUsable(): bool
    {
        return $this->enabled
            && filled($this->client_id)
            && filled($this->client_secret)
            && filled($this->authorization_endpoint)
            && filled($this->token_endpoint)
            && filled($this->issuer);
    }

    /**
     * The scopes to request, de-duplicated and always including `openid` — an
     * authorisation request without it is not an OpenID Connect request at all,
     * and the mistake is easy to make by editing the field down to `profile email`.
     *
     * @return list<string>
     */
    public function scopeList(): array
    {
        $scopes = preg_split('/[\s,]+/', (string) $this->scopes, flags: PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(['openid', ...$scopes]));
    }

    /**
     * @return list<string>
     */
    public function allowedGroupList(): array
    {
        return array_values(array_filter(array_map(
            static fn ($group): string => trim((string) $group),
            $this->allowed_groups ?? [],
        ), static fn (string $group): bool => $group !== ''));
    }
}
