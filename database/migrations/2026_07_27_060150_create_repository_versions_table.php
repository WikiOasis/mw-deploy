<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One checkout of one repository, in one core version.
     *
     * This is the thing a deployment actually acts on: Echo-under-1.45 is a
     * different directory on disk from Echo-under-1.46, can sit on a different
     * ref, and can be removed independently.
     */
    public function up(): void
    {
        Schema::create('repository_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();

            // Null for repositories that live outside a versions/<ver> subtree —
            // mw-config, or an extension deliberately kept at the top level.
            $table->foreignId('mediawiki_version_id')->nullable()
                ->constrained('mediawiki_versions')->cascadeOnDelete();

            // Resolved once at registration and stored, so the layout is
            // auditable rather than recomputed (and possibly drifting) later.
            $table->string('path');

            /*
             * How this checkout decides which ref to deploy:
             *
             *   pinned         a fixed branch or tag for this version, e.g.
             *                  REL1_45. The default, and what makes a version a
             *                  version rather than a moving target.
             *   default_branch follow the repository's default_branch.
             *   floating       no pin; the operator picks every time.
             *
             * Any of these can still be overridden per deployment — the pin is a
             * default, not a restriction.
             */
            $table->string('ref_mode')->default('pinned');
            $table->string('tracked_ref_type')->nullable(); // branch|commit
            $table->string('tracked_ref_value')->nullable();

            // present|undeployed. An undeployed row is kept so the checkout can
            // be restored without re-registering, and so history resolves.
            $table->string('status')->default('present');

            $table->timestamp('registered_at')->nullable();
            $table->timestamp('undeployed_at')->nullable();
            $table->timestamps();

            $table->unique(['repository_id', 'mediawiki_version_id']);
            $table->unique('path');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repository_versions');
    }
};
