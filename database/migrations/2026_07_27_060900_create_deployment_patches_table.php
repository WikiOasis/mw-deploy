<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployment_patches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patch_id')->constrained()->cascadeOnDelete();
            $table->boolean('applied')->default(false);

            // Which ref the patch was applied against, so "did this patch
            // actually apply cleanly against what staging was at" is answerable
            // after the fact.
            $table->string('applied_to_ref')->nullable();

            $table->timestamps();

            $table->unique(['deployment_id', 'patch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_patches');
    }
};
