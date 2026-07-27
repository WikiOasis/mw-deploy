<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();

            // Nullable: a patch can be repo-specific or freeform.
            $table->foreignId('target_repo_id')->nullable()
                ->constrained('repositories')->nullOnDelete();

            // Directory the patch applies against, relative to the MediaWiki
            // root. Same meaning as --patch-target in the CLI, but stored once
            // here instead of retyped on every deploy.
            $table->string('target_path');

            // Path of the stored patch file on the patches disk.
            $table->string('file_path');
            $table->string('original_filename')->nullable();

            // 'unified' => patch -p1 / patch --dry-run
            // 'git'     => git apply / git apply --check
            $table->string('format')->default('unified');

            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_checked_at')->nullable();
            $table->boolean('last_check_ok')->nullable();
            $table->text('last_check_detail')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patches');
    }
};
