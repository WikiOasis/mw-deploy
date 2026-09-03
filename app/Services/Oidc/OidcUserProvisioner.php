<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use App\Models\OidcRoleMapping;
use App\Models\OidcSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns an identity the IdP vouched for into a console account with roles.
 *
 * Three decisions live here, and they are the ones worth being explicit about on
 * a tool that can deploy to production:
 *
 *  * **Who this is.** The subject claim, and only the subject claim, once an
 *    account has one. Emails get reassigned; `sub` does not.
 *  * **Whether an existing account may be claimed.** Only on a *verified* email.
 *    An IdP that lets someone set an unverified address would otherwise be a way
 *    to walk into an existing account by typing its email into a profile page.
 *  * **What they may do.** Whatever the group mapping says, and nothing else.
 *    Permissions are never read from the IdP directly — groups map to roles, and
 *    roles are what the access screen already shows.
 */
final class OidcUserProvisioner
{
    /**
     * @throws OidcException
     */
    public function resolve(OidcIdentity $identity, OidcSettings $settings): User
    {
        $this->assertAllowed($identity, $settings);

        $roleIds = OidcRoleMapping::rolesFor($identity->groups);

        return DB::transaction(function () use ($identity, $settings, $roleIds): User {
            $user = $this->findOrCreate($identity, $settings);

            $this->applyRoles($user, $roleIds, $settings);

            $user->forgetPermissionCache();

            return $user->fresh(['roles']) ?? $user;
        });
    }

    /**
     * The optional group allow-list. Empty means the IdP's own answer is enough:
     * anyone it authenticates gets in, with whatever their groups map to — which
     * may be no roles at all, i.e. an account that can sign in and see an empty
     * launcher.
     *
     * @throws OidcException
     */
    private function assertAllowed(OidcIdentity $identity, OidcSettings $settings): void
    {
        $allowed = $settings->allowedGroupList();

        if ($allowed === []) {
            return;
        }

        $held = array_map(mb_strtolower(...), $identity->groups);

        foreach ($allowed as $group) {
            if (in_array(mb_strtolower($group), $held, true)) {
                return;
            }
        }

        throw OidcException::because(
            'Your account is not in a group that may use this console.',
            'subject '.$identity->subject.' held none of the allowed groups',
        );
    }

    /**
     * @throws OidcException
     */
    private function findOrCreate(OidcIdentity $identity, OidcSettings $settings): User
    {
        $existing = User::query()->where('oidc_subject', $identity->subject)->first();

        if ($existing !== null) {
            return $this->refresh($existing, $identity);
        }

        $byEmail = $identity->email === ''
            ? null
            : User::query()->whereRaw('LOWER(email) = ?', [$identity->email])->first();

        if ($byEmail !== null) {
            if (! $identity->emailVerified) {
                throw OidcException::because(
                    'The identity provider did not confirm your email address, so it cannot be linked to an existing account here. Ask an administrator to link it.',
                    'refused to claim account '.$byEmail->getKey().' on an unverified email',
                );
            }

            /*
             * An account already claimed by a different subject is not
             * re-claimed on the strength of an email address. Reaching here
             * means the IdP is now asserting that a second identity owns an
             * account the first one holds — a renumbering at the provider, or an
             * address that has been reassigned to somebody else. Either way it
             * is an administrator's decision, made by clearing the old subject,
             * not something to infer from a claim.
             */
            if (filled($byEmail->oidc_subject) && $byEmail->oidc_subject !== $identity->subject) {
                throw OidcException::because(
                    'This console\'s account for that email address is already linked to a different identity-provider account. Ask an administrator to unlink it first.',
                    'refused to relink account '.$byEmail->getKey().': subject changed',
                );
            }

            /*
             * First single sign-on for an account that already existed — a local
             * account an administrator created, or one made before SSO was
             * switched on. Linking it is the point: it keeps the person's roles,
             * their TOTP enrolment and their deployment history.
             */
            $byEmail->oidc_subject = $identity->subject;

            // No email address: the account id identifies it, and the log is
            // read by more people than the access screen is.
            Log::info('OIDC linked an existing account', ['user_id' => $byEmail->getKey()]);

            return $this->refresh($byEmail, $identity);
        }

        if (! $settings->create_users) {
            throw OidcException::because(
                'There is no account on this console for '.($identity->email ?: 'your identity provider account').'. Ask an administrator to create one.',
                'account creation is switched off and no account matched',
            );
        }

        if ($identity->email === '') {
            throw OidcException::because(
                'The identity provider did not release an email address, so an account cannot be created.',
                'no email claim and create_users is on',
            );
        }

        $user = new User;
        $user->email = $identity->email;
        $user->name = $identity->name;
        // No password at all, rather than one nobody knows: the authentication
        // callback refuses password sign-in for these accounts outright.
        $user->password = null;
        $user->oidc_subject = $identity->subject;
        $user->email_verified_at = $identity->emailVerified ? now() : null;
        $user->oidc_synced_at = now();
        $user->save();

        Log::info('OIDC provisioned a new account', [
            'user_id' => $user->getKey(),
            'groups' => $identity->groups,
        ]);

        return $user;
    }

    /**
     * Keep the local copy of the IdP's own fields current, without letting it
     * walk over another account's email.
     */
    private function refresh(User $user, OidcIdentity $identity): User
    {
        if ($identity->name !== '') {
            $user->name = $identity->name;
        }

        $emailIsFree = $identity->email !== ''
            && mb_strtolower((string) $user->email) !== $identity->email
            && ! User::query()
                ->whereRaw('LOWER(email) = ?', [$identity->email])
                ->whereKeyNot($user->getKey())
                ->exists();

        if ($emailIsFree && $identity->emailVerified) {
            $user->email = $identity->email;
        }

        if ($identity->emailVerified && $user->email_verified_at === null) {
            $user->email_verified_at = now();
        }

        $user->oidc_synced_at = now();
        $user->save();

        return $user;
    }

    /**
     * @param  list<int>  $roleIds
     */
    private function applyRoles(User $user, array $roleIds, OidcSettings $settings): void
    {
        $mappingsExist = OidcRoleMapping::query()->exists();

        /*
         * Nothing mapped at all: leave roles alone. Switching SSO on before
         * anyone has written the mapping would otherwise strip every role from
         * every account that signs in — including, on the first sign-in, the
         * account that was about to write the mapping.
         */
        if (! $mappingsExist) {
            return;
        }

        if ($user->wasRecentlyCreated) {
            $user->roles()->sync($roleIds);

            return;
        }

        // Off means the mapping seeds an account and roles are managed here
        // afterwards; on means the IdP is the source of truth and revoking a
        // group there revokes the role here.
        if ($settings->sync_roles) {
            $user->roles()->sync($roleIds);
        } else {
            $user->roles()->syncWithoutDetaching($roleIds);
        }
    }
}
