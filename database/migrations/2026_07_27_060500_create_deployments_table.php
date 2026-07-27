<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending'); // pending|running|done|failed|aborted

            /*
             * What the operator set out to do: deploy | undeploy | version_create
             * | version_undeploy. Every intent runs through the same pipeline —
             * this drives permission checks and how history reads, not control
             * flow. A deployment's actual work is in deployment_repo_refs.action.
             */
            $table->string('intent')->default('deploy');

            // parallel, force, l10n, rollout, servers[]
            $table->json('options');

            // Set when this deployment created or removed a whole core version.
            $table->foreignId('mediawiki_version_id')->nullable()
                ->constrained('mediawiki_versions')->nullOnDelete();

            // Set when this deployment is a rollback of another one, so history
            // can render "Rollback of #142" instead of a normal ref list.
            $table->foreignId('rolls_back_deployment_id')->nullable()
                ->constrained('deployments')->nullOnDelete();

            // Blocking operator prompt, replacing the curses Prompter. The job
            // writes `pending_decision` then polls for `decision_response`.
            $table->string('pending_decision')->nullable();
            $table->json('pending_decision_context')->nullable();
            $table->timestamp('pending_decision_requested_at')->nullable();
            $table->string('decision_response')->nullable();
            $table->foreignId('decision_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decision_answered_at')->nullable();

            $table->text('failure_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('intent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployments');
    }
};
