<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployment_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();

            // Salt minion id the step ran against. Denormalised on purpose: a
            // target may be removed from deploy_targets later and the history
            // still has to say which box it touched.
            $table->string('target_hostname');

            $table->string('step_name');

            // Free-form disambiguator, e.g. the repository name for a
            // git-checkout step or the patch name for patch-apply.
            $table->string('subject')->nullable();

            $table->string('status')->default('pending');

            // The exact salt argv that ran, for the review screen and audit.
            $table->text('command')->nullable();

            $table->text('log')->nullable(); // append-only
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['deployment_id', 'target_hostname']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_steps');
    }
};
