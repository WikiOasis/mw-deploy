<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional finer-grained scoping from section 3.5.2: grant a user or role
     * the ability to deploy only specific repositories.
     *
     * Semantics are deliberately opt-in per repository: a repository with no
     * rows here is governed purely by the coarse `deploy.<type>` permission. A
     * repository with at least one row additionally requires the actor to match
     * one of those rows. That way turning on per-repo scoping for one extension
     * does not silently lock everyone out of every other extension.
     */
    public function up(): void
    {
        Schema::create('repository_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['repository_id', 'user_id']);
            $table->unique(['repository_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repository_permissions');
    }
};
