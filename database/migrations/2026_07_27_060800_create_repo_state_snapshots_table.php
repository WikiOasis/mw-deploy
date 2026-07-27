<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per checkout, what staging was like *before* this deployment touched it.
     * The undo point that makes rollback possible; the original CLI has no
     * equivalent because it only ever knew "whatever origin currently has".
     *
     * Presence is recorded alongside the ref, which is what makes rollback
     * symmetric across every intent:
     *
     *   was present, now absent   → rollback re-clones and re-checks-out
     *   was absent, now present   → rollback removes it again
     *   was present at a old ref  → rollback checks that ref back out
     *
     * so undoing an undeploy, undoing an added extension and undoing a normal
     * ref change are all the same code path.
     */
    public function up(): void
    {
        Schema::create('repo_state_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_version_id')->constrained()->cascadeOnDelete();

            $table->boolean('previous_present')->default(true);
            $table->string('previous_ref_type')->nullable(); // branch|commit
            $table->string('previous_ref_value')->nullable();

            $table->boolean('new_present')->default(true);
            $table->string('new_ref_type')->nullable();
            $table->string('new_ref_value')->nullable();

            $table->timestamps();

            $table->unique(['deployment_id', 'repository_version_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repo_state_snapshots');
    }
};
