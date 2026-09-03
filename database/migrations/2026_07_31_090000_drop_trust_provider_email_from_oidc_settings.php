<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the `email_verified` trust setting, because the console no longer asks
 * the question it answered.
 *
 * Linking an existing account used to require the provider to vouch for the
 * address, on the reasoning that an IdP letting someone type an arbitrary email
 * into a profile page would be a way to walk into somebody else's account. That
 * threat needs an IdP that hands out accounts to strangers. The provider this is
 * built for is internal and gates access to this application behind manual
 * approval, so the address it states is as trustworthy as the identity it states
 * — and treating one as gospel while interrogating the other was incoherent.
 *
 * `email_verified` is still read: it is what decides whether the local
 * `email_verified_at` is set. It just no longer decides who may sign in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oidc_settings', function (Blueprint $table) {
            $table->dropColumn('trust_provider_email');
        });
    }

    public function down(): void
    {
        Schema::table('oidc_settings', function (Blueprint $table) {
            $table->boolean('trust_provider_email')->default(true)->after('groups_claim');
        });
    }
};
