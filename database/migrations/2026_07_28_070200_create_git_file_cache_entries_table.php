<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tree listings and blob content for the file-at-commit browser, keyed by the
     * *resolved* commit SHA rather than a branch name — see
     * GitFileBrowser::resolve() — so every row is immutable and correct forever
     * once written, with no staleness question to ask.
     *
     * `disk_path` holds blobs too large to comfortably store as a JSON payload
     * (see config('mwdeploy.git.blob_disk_threshold')); when set, `payload` is
     * empty and the content lives under storage/app/git-file-cache instead.
     *
     * `last_accessed_at` is what the pruning job (mwdeploy:prune-git-file-cache)
     * keys off: rows untouched for 24h are reclaimed, since a browsed commit is
     * not something the fleet needs to remember indefinitely.
     */
    public function up(): void
    {
        Schema::create('git_file_cache_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_version_id')->constrained()->cascadeOnDelete();
            $table->string('commit_sha', 40);
            $table->string('kind'); // tree|blob
            $table->string('path', 512);
            $table->json('payload')->nullable();
            $table->string('disk_path')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->boolean('truncated')->default(false);
            $table->boolean('binary')->default(false);
            $table->timestamp('last_accessed_at');
            $table->timestamps();

            $table->unique(['repository_version_id', 'commit_sha', 'kind', 'path'], 'git_file_cache_entries_lookup');
            $table->index('last_accessed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('git_file_cache_entries');
    }
};
