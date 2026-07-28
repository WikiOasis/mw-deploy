<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            // A manual "abort this running deployment" request, separate from
            // pending_decision/decision_response: those answer a specific canary
            // prompt the job raised, this is an operator interrupting a deployment
            // that has not (yet) hit one. The runner polls this at its checkpoints,
            // the same way it polls decision_response inside a blocking prompt.
            $table->timestamp('abort_requested_at')->nullable();
            $table->foreignId('abort_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('abort_rollback')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('abort_requested_by');
            $table->dropColumn(['abort_requested_at', 'abort_rollback']);
        });
    }
};
