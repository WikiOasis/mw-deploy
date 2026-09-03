<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two settings the first pass at single sign-on did without.
 *
 * Both exist because an install that has SSO working wants different answers
 * from one that is still setting it up, and neither answer is safe as a
 * hard-coded default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oidc_settings', function (Blueprint $table) {
            /*
             * Whether the sign-in page still offers a password box.
             *
             * Default on, and it can only be switched off while single sign-on is
             * actually usable — see OidcSettings::passwordLoginAllowed(), which
             * also turns it back on by itself if SSO is later disabled or its
             * configuration is left incomplete. An ops console that can be locked
             * out of by a checkbox is not one to put in front of a fleet.
             */
            $table->boolean('password_login_enabled')->default(true)->after('label');

            /*
             * Whether an address the provider states without an `email_verified`
             * claim counts as verified.
             *
             * The claim is optional in the spec and plenty of providers — Authentik
             * among them — simply do not send it, which left every attempt to link
             * an existing account refused. This makes the trust explicit rather
             * than either assuming it silently or refusing forever.
             *
             * `email_verified: false` is a different thing entirely: that is the
             * provider saying it does *not* vouch for the address, and it is
             * refused whatever this is set to.
             */
            $table->boolean('trust_provider_email')->default(true)->after('groups_claim');
        });
    }

    public function down(): void
    {
        Schema::table('oidc_settings', function (Blueprint $table) {
            $table->dropColumn(['password_login_enabled', 'trust_provider_email']);
        });
    }
};
