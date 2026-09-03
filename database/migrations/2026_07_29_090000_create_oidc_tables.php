<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single sign-on against a third-party OpenID Connect provider.
 *
 * The provider is configured from the console's own settings screen rather than
 * from the environment, because the people who need to change it — rotate a
 * client secret, follow the IdP to a new hostname, add a group — are not
 * necessarily the people who can edit .env and restart php-fpm on the box. One
 * row, because an install trusts one identity provider; a second one would be a
 * second answer to "who are you", which is not a thing an ops console wants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oidc_settings', function (Blueprint $table) {
            $table->id();

            /*
             * The single-row invariant, enforced by the database rather than by
             * everyone remembering it. Every write goes to the row where
             * `singleton` is true, and the unique index means a second one
             * cannot be inserted — not by a concurrent first save from two
             * browser tabs, and not by hand.
             *
             * An install trusts one identity provider; two rows would be two
             * answers to "who are you", and sign-in would use whichever the
             * database happened to return first.
             */
            $table->boolean('singleton')->default(true)->unique();

            $table->boolean('enabled')->default(false);

            // What the button on the sign-in page says. "Sign in with Authentik"
            // is more use to the person reading it than "Sign in with OIDC".
            $table->string('label')->default('single sign-on');

            /*
             * Discovery is the intended way in: paste the issuer, press Fetch,
             * and the endpoints below are filled from
             * /.well-known/openid-configuration. They are stored rather than
             * fetched per sign-in so that an IdP whose discovery document is
             * briefly unreachable does not take sign-in down with it, and so
             * that an IdP without discovery can be configured by hand.
             */
            $table->string('discovery_url')->nullable();
            $table->string('issuer')->nullable();

            $table->string('client_id')->nullable();
            // Encrypted at rest: a console-wide credential that can mint
            // identities is not something to leave readable in a mysqldump.
            $table->text('client_secret')->nullable();

            $table->string('authorization_endpoint')->nullable();
            $table->string('token_endpoint')->nullable();
            $table->string('userinfo_endpoint')->nullable();
            $table->string('jwks_uri')->nullable();
            /*
             * Recorded by discovery but not used yet: signing out of the console
             * deliberately does not sign you out of the IdP, and RP-initiated
             * logout is a separate decision from single sign-on itself. Kept so
             * that decision does not need another migration.
             */
            $table->string('end_session_endpoint')->nullable();

            $table->string('scopes')->default('openid profile email groups');

            // Which claim carries group membership. There is no standard one:
            // Keycloak and Authentik say `groups`, Entra says `roles` or `wids`,
            // Okta is whatever the admin named the claim.
            $table->string('groups_claim')->default('groups');

            // Provision an account the first time someone signs in, rather than
            // refusing anyone an administrator has not pre-created.
            $table->boolean('create_users')->default(true);

            // Re-apply the group mapping on every sign-in, so revoking a group
            // at the IdP revokes the console role too. Off means the mapping is
            // a starting point and roles are managed here afterwards.
            $table->boolean('sync_roles')->default(true);

            // Optional allow-list. Empty means any account the IdP authenticates
            // may sign in (and gets whatever its groups map to, possibly nothing).
            $table->json('allowed_groups')->nullable();

            $table->timestamp('discovered_at')->nullable();

            $table->timestamps();
        });

        /*
         * IdP group -> console role. Many-to-many: one group can grant several
         * roles, and one role can be granted by several groups, which is what
         * happens as soon as two teams share a permission set.
         */
        Schema::create('oidc_role_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('group');
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['group', 'role_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            /*
             * The IdP's subject claim, which is the only stable identifier it
             * offers: email addresses get changed, `sub` does not. Matching is
             * by subject first and by verified email only when an account has no
             * subject recorded yet — see OidcUserProvisioner.
             */
            $table->string('oidc_subject')->nullable()->unique()->after('email');
            $table->timestamp('oidc_synced_at')->nullable()->after('oidc_subject');

            /*
             * Nullable so a provisioned account can exist without one. It is not
             * a password nobody knows — it is no password at all, and the
             * authentication callback refuses to check credentials against it,
             * rather than relying on a hash comparison against an empty string.
             */
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        /*
         * Rolling back has to restore the contract `up()` changed, and
         * users.password was non-null before it. Accounts this feature
         * provisioned have no password at all, so there is nothing safe to put
         * in the column for them: inventing one would leave an account whose
         * password nobody knows but which the login form will happily try, and
         * deleting them would take their deployment history with them.
         *
         * So the rollback stops, and says which accounts are in the way. An
         * administrator gives them passwords (or removes them) and runs it
         * again. An install that never used single sign-on rolls back cleanly.
         */
        $passwordless = DB::table('users')->whereNull('password')->count();

        if ($passwordless > 0) {
            throw new RuntimeException(
                'Cannot roll back: '.$passwordless.' account(s) were provisioned by single sign-on and have no password, '.
                'and users.password is about to be made non-null again. Give those accounts passwords, or remove them, then retry.',
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['oidc_subject']);
            $table->dropColumn(['oidc_subject', 'oidc_synced_at']);
        });

        // Its own statement: a column change alongside two drops in one
        // blueprint is the kind of thing that behaves differently per driver.
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });

        Schema::dropIfExists('oidc_role_mappings');
        Schema::dropIfExists('oidc_settings');
    }
};
