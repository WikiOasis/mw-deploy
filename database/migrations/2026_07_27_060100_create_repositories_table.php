<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A repository as a *logical* thing: "the Echo extension", not "Echo as
     * checked out under 1.45".
     *
     * The per-version checkouts live in repository_versions. Keeping them apart
     * is what makes "deploy Echo to every version", "add Echo to a new version"
     * and "remove Echo from one version" expressible at all.
     */
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // core|extension|skin|config
            $table->string('git_url');

            // Fallback ref for a version whose ref_mode is default_branch, and
            // the branch used when cloning a brand new checkout.
            $table->string('default_branch')->default('master');

            // Extensions/skins the farm actually enables, per mw-config. Purely
            // informational; drives the "in use" filter on the repo browser.
            $table->boolean('in_use')->default(false);

            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One logical Echo, however many versions it is checked out in.
            $table->unique(['type', 'name']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};
