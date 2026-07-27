<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployment_repo_refs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->string('ref_type'); // branch|commit
            $table->string('ref_value');
            $table->timestamps();

            $table->unique(['deployment_id', 'repository_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_repo_refs');
    }
};
