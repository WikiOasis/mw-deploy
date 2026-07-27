<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One line item of a deployment: do this to this checkout.
     */
    public function up(): void
    {
        Schema::create('deployment_repo_refs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_version_id')->constrained()->cascadeOnDelete();

            // deploy: check out ref_value and sync it.
            // undeploy: remove the directory from staging and every server.
            $table->string('action')->default('deploy');

            // Null for an undeploy — there is no ref to check out.
            $table->string('ref_type')->nullable(); // branch|commit
            $table->string('ref_value')->nullable();

            $table->timestamps();

            $table->unique(['deployment_id', 'repository_version_id']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_repo_refs');
    }
};
