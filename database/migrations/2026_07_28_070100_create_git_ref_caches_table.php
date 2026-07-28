<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persistent branch/commit listings, per checkout. Replaces the 60-second
     * Cache::remember the providers used to lean on: once a row exists here it is
     * authoritative until something explicitly refreshes it (see
     * CachedGitRefProvider::refresh()) — there is no TTL to silently go stale
     * against, and no TTL to silently serve stale data either.
     *
     * `branch` is nullable because branch listings are not keyed per branch;
     * commit listings are, since each tracked branch has its own recent history.
     */
    public function up(): void
    {
        Schema::create('git_ref_caches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_version_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // branches|commits
            $table->string('branch')->nullable();
            $table->json('payload');
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['repository_version_id', 'kind', 'branch']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('git_ref_caches');
    }
};
