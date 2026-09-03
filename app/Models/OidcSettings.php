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
    'enabled', 'label', 'password_login_enabled', 'discovery_url', 'issuer',
    'client_id', 'client_secret', 'authorization_endpoint', 'token_endpoint',
    'userinfo_endpoint', 'jwks_uri', 'end_session_endpoint', 'scopes',
    'groups_claim', 'trust_provider_email', 'create_users', 'sync_roles',
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
        'singleton' => true,
        'enabled' => false,
        'label' => 'single sign-on',
        'password_login_enabled' => true,
        'scopes' => 'openid profile email groups',
        'groups_claim' => 'groups',
        'trust_provider_email' => true,
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
            'password_login_enabled' => 'boolean',
            'trust_provider_email' => 'boolean',
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
     * Addressed by the `singleton` column rather than by "whichever row comes
     * back first", so what sign-in reads is what the settings screen wrote. That
     * column is uniquely indexed, so a save cannot add a second row even if two
     * administrators press Save at the same moment — the second fails loudly
     * instead of creating a rival configuration.
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
        return self::query()->firstOrNew(['singleton' => true]);
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
     * Whether the sign-in page still accepts a password.
     *
     * Three ways it stays available, and they are all deliberate:
     *
     *  * it has not been switched off;
     *  * single sign-on is not currently usable — switched off, or its
     *    configuration left incomplete — because the alternative is a console
     *    with no way in at all;
     *  * the break-glass override is set in the environment, which is how someone
     *    with a shell on the box gets back in on the day the IdP is what broke,
     *    without needing database access to undo a checkbox.
     *
     * The last two are the point. This is a tool that deploys to production: the
     * setting is allowed to make password sign-in *unavailable*, never to make
     * the console unreachable.
     */
    public function passwordLoginAllowed(): bool
    {
        if (config('console.force_password_login') === true) {
            return true;
        }

        if (! $this->isUsable()) {
            return true;
        }

        return (bool) $this->password_login_enabled;
    }

    /**
     * Whether an address the provider stated without an `email_verified` claim
     * may be treated as verified.
     *
     * Only ever consulted for a *missing* claim. An explicit
     * `email_verified: false` is the provider saying it does not vouch for the
     * address, and that is refused regardless — see OidcUserProvisioner.
     */
    public function trustsProviderEmail(): bool
    {
        return (bool) $this->trust_provider_email;
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
