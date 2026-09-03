<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OidcRoleMapping;
use App\Models\OidcSettings;
use App\Models\Role;
use App\Models\User;
use App\Services\Oidc\OidcDiscovery;
use App\Services\Oidc\OidcException;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * The single sign-on configuration, editable from the console.
 *
 * Configuration lives in the database rather than in .env on purpose: rotating a
 * client secret, following the IdP to a new hostname or adding a group is
 * ordinary access administration, and it should not need a shell on the box, an
 * editor and a php-fpm reload. What it does need is its own permission — see
 * Permissions::SETTINGS_MANAGE — because whoever holds it decides which identity
 * provider this console believes.
 *
 * The client secret is write-only across this API. It goes in, it is never sent
 * back out, and the screen shows whether one is set rather than what it is.
 */
final class OidcSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->authorize(Permissions::SETTINGS_MANAGE);

        return response()->json($this->payload());
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorize(Permissions::SETTINGS_MANAGE);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'label' => ['required', 'string', 'max:80'],

            'discovery_url' => ['nullable', 'string', 'max:500', 'url'],
            'issuer' => ['required_if:enabled,true', 'nullable', 'string', 'max:500', 'url'],

            'client_id' => ['required_if:enabled,true', 'nullable', 'string', 'max:255'],
            /*
             * Absent means "leave the stored secret alone", which is what
             * submitting a form whose secret field shows a placeholder should
             * do. An explicit null clears it.
             */
            'client_secret' => ['sometimes', 'nullable', 'string', 'max:500'],

            'authorization_endpoint' => ['required_if:enabled,true', 'nullable', 'string', 'max:500', 'url'],
            'token_endpoint' => ['required_if:enabled,true', 'nullable', 'string', 'max:500', 'url'],
            'userinfo_endpoint' => ['nullable', 'string', 'max:500', 'url'],
            // Required when enabled: without a key set there is no way to check
            // an ID token's signature, and this flow refuses to skip that.
            'jwks_uri' => ['required_if:enabled,true', 'nullable', 'string', 'max:500', 'url'],
            'end_session_endpoint' => ['nullable', 'string', 'max:500', 'url'],

            'scopes' => ['required', 'string', 'max:255'],
            'groups_claim' => ['required', 'string', 'max:120'],

            'create_users' => ['required', 'boolean'],
            'sync_roles' => ['required', 'boolean'],

            'allowed_groups' => ['sometimes', 'array'],
            'allowed_groups.*' => ['string', 'max:190'],

            'role_mappings' => ['sometimes', 'array'],
            'role_mappings.*.group' => ['required', 'string', 'max:190'],
            'role_mappings.*.role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
        ], [
            'jwks_uri.required_if' => 'The key set URL is required: ID token signatures are always checked.',
        ]);

        $settings = OidcSettings::current();

        $settings->fill([
            'enabled' => $validated['enabled'],
            'label' => $validated['label'],
            'discovery_url' => $validated['discovery_url'] ?? null,
            'issuer' => $validated['issuer'] ?? null,
            'client_id' => $validated['client_id'] ?? null,
            'authorization_endpoint' => $validated['authorization_endpoint'] ?? null,
            'token_endpoint' => $validated['token_endpoint'] ?? null,
            'userinfo_endpoint' => $validated['userinfo_endpoint'] ?? null,
            'jwks_uri' => $validated['jwks_uri'] ?? null,
            'end_session_endpoint' => $validated['end_session_endpoint'] ?? null,
            'scopes' => $validated['scopes'],
            'groups_claim' => $validated['groups_claim'],
            'create_users' => $validated['create_users'],
            'sync_roles' => $validated['sync_roles'],
            'allowed_groups' => array_values(array_filter(
                array_map('trim', $validated['allowed_groups'] ?? []),
                static fn (string $group): bool => $group !== '',
            )),
        ]);

        if (array_key_exists('client_secret', $validated)) {
            $settings->client_secret = $validated['client_secret'];
        }

        /*
         * Refuse to switch on a configuration that cannot work. The alternative
         * is a sign-in button that takes people to an error page, which they
         * will report as the console being broken.
         */
        if ($validated['enabled'] && ! $settings->isUsable()) {
            return response()->json([
                'message' => 'Single sign-on cannot be switched on until the issuer, client id, client secret and the authorisation and token endpoints are all set.',
                'errors' => ['enabled' => ['Fill in the provider details first, then switch it on.']],
            ], 422);
        }

        DB::transaction(function () use ($settings, $validated): void {
            $settings->save();

            if (array_key_exists('role_mappings', $validated)) {
                $this->syncMappings($validated['role_mappings']);
            }
        });

        Log::notice('Single sign-on configuration changed', [
            'by' => $this->actorId(),
            'enabled' => $settings->enabled,
            'issuer' => $settings->issuer,
        ]);

        return response()->json([
            ...$this->payload(),
            'message' => $settings->enabled
                ? 'Single sign-on is on. The sign-in page now offers it.'
                : 'Single sign-on configuration saved. It is switched off.',
        ]);
    }

    /**
     * Read the IdP's discovery document and hand back the endpoints, without
     * saving anything.
     *
     * Separate from the save so the administrator sees what was found and can
     * still change it — an IdP behind a split-horizon DNS will advertise
     * endpoints this host cannot reach, and that is a thing to notice before
     * turning sign-in over to it, not after.
     */
    public function discover(Request $request, OidcDiscovery $discovery): JsonResponse
    {
        $this->authorize(Permissions::SETTINGS_MANAGE);

        $validated = $request->validate([
            'discovery_url' => ['required', 'string', 'max:500', 'url'],
        ]);

        try {
            $document = $discovery->fetch($validated['discovery_url']);
        } catch (OidcException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => ['discovery_url' => [$exception->getMessage()]],
            ], 422);
        }

        // Record that the provider's configuration was read, so the screen can
        // say how stale the stored endpoints are.
        $settings = OidcSettings::current();
        $settings->discovery_url = $validated['discovery_url'];
        $settings->discovered_at = now();
        $settings->save();

        return response()->json([
            'discovery' => $document,
            'document_url' => $discovery->documentUrl($validated['discovery_url']),
            'message' => 'Read the provider\'s configuration. Check the endpoints, then save.',
        ]);
    }

    /**
     * @param  list<array{group: string, role_id: int}>  $mappings
     */
    private function syncMappings(array $mappings): void
    {
        $keep = [];

        foreach ($mappings as $mapping) {
            $group = trim($mapping['group']);

            if ($group === '') {
                continue;
            }

            $keep[] = (int) OidcRoleMapping::query()->updateOrCreate([
                'group' => $group,
                'role_id' => $mapping['role_id'],
            ])->getKey();
        }

        OidcRoleMapping::query()->whereNotIn('id', $keep ?: [0])->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $settings = OidcSettings::current();

        return [
            'settings' => [
                'enabled' => (bool) $settings->enabled,
                'label' => (string) $settings->label,
                'discovery_url' => $settings->discovery_url,
                'issuer' => $settings->issuer,
                'client_id' => $settings->client_id,
                // Never the secret itself, only whether there is one.
                'client_secret_set' => filled($settings->client_secret),
                'authorization_endpoint' => $settings->authorization_endpoint,
                'token_endpoint' => $settings->token_endpoint,
                'userinfo_endpoint' => $settings->userinfo_endpoint,
                'jwks_uri' => $settings->jwks_uri,
                'end_session_endpoint' => $settings->end_session_endpoint,
                'scopes' => (string) $settings->scopes,
                'groups_claim' => (string) $settings->groups_claim,
                'create_users' => (bool) $settings->create_users,
                'sync_roles' => (bool) $settings->sync_roles,
                'allowed_groups' => $settings->allowedGroupList(),
                'usable' => $settings->isUsable(),
                'discovered_at' => $settings->discovered_at?->toIso8601String(),
            ],
            /*
             * The redirect URI to register at the IdP, computed from this
             * install's own URL. Getting it wrong is the single most common
             * reason a first OIDC setup fails, so it is shown rather than
             * described.
             */
            'redirect_uri' => route('oidc.callback'),
            'role_mappings' => OidcRoleMapping::listed()
                ->map(fn (OidcRoleMapping $mapping): array => [
                    'id' => $mapping->getKey(),
                    'group' => (string) $mapping->group,
                    'role_id' => (int) $mapping->role_id,
                    'role' => $mapping->role?->name,
                ])->values()->all(),
            'roles' => Role::query()->orderBy('name')->get()
                ->map(fn (Role $role): array => [
                    'id' => $role->getKey(),
                    'name' => $role->name,
                    'description' => $role->description,
                ])->values()->all(),
            // So the screen can say how many accounts this provider already owns
            // before someone switches it off.
            'linked_accounts' => User::query()->whereNotNull('oidc_subject')->count(),
        ];
    }

    private function actorId(): ?int
    {
        $user = request()->user();

        return $user instanceof User ? (int) $user->getKey() : null;
    }
}
