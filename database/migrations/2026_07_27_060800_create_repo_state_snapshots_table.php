<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per repository, what staging was at *before* this deployment touched it.
     * This is the undo point that makes rollback possible; the original CLI has
     * no equivalent because it only ever knew "whatever origin currently has".
     */
    public function up(): void
    {
        Schema::create('repo_state_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->string('previous_ref_type')->nullable(); // branch|commit
            $table->string('previous_ref_value')->nullable();
            $table->string('new_ref_type');
            $table->string('new_ref_value');
            $table->timestamps();

            $table->unique(['deployment_id', 'repository_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repo_state_snapshots');
    }
};
