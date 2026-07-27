<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Columns for adopting a MediaWiki farm that already exists.
     *
     * Two distinct ideas are being recorded, and conflating them would be a bug:
     *
     *   the *pin*      what the portal will deploy — tracked_ref_* on
     *                  repository_versions, which an operator chooses.
     *   the *observation*  what `mwdeploy-shim tree-scan` last saw on disk —
     *                  observed_ref_* here, which nobody chooses.
     *
     * Keeping the observation separate is what lets the repository screen say "the
     * registry pins REL1_45 but staging is sitting on REL1_44" instead of silently
     * overwriting one with the other.
     */
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            // Set when a repository entered the registry by import rather than by
            // someone filling in the form. Worth knowing when the git URL looks
            // odd: an imported one was read off disk, not typed.
            $table->timestamp('discovered_at')->nullable()->after('active');

            // extension.json's declared name, version, licence and MediaWiki
            // requirement. Displayed, never deployed from.
            $table->json('manifest')->nullable()->after('discovered_at');
        });

        Schema::table('repository_versions', function (Blueprint $table) {
            $table->timestamp('discovered_at')->nullable()->after('status');

            $table->string('observed_ref_type')->nullable()->after('discovered_at');
            $table->string('observed_ref_value')->nullable()->after('observed_ref_type');

            // Full SHA even when observed_ref_value is a branch name, so drift
            // between two checkouts of the same branch is still visible.
            $table->string('observed_commit', 64)->nullable()->after('observed_ref_value');
            $table->timestamp('observed_at')->nullable()->after('observed_commit');
        });

        Schema::table('mediawiki_versions', function (Blueprint $table) {
            $table->timestamp('discovered_at')->nullable()->after('status');

            // MW_VERSION as read out of includes/Defines.php: '1.45.0' for a
            // versions/1.45 tree. A mismatch is drift worth surfacing.
            $table->string('core_version')->nullable()->after('discovered_at');
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropColumn(['discovered_at', 'manifest']);
        });

        Schema::table('repository_versions', function (Blueprint $table) {
            $table->dropColumn([
                'discovered_at', 'observed_ref_type', 'observed_ref_value', 'observed_commit', 'observed_at',
            ]);
        });

        Schema::table('mediawiki_versions', function (Blueprint $table) {
            $table->dropColumn(['discovered_at', 'core_version']);
        });
    }
};
