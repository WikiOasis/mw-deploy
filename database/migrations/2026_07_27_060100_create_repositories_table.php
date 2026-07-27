<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // core|extension|skin|config
            $table->string('git_url');
            $table->string('default_branch')->default('master');

            // MediaWiki core version this checkout belongs to. Null for repos
            // that live outside a versions/<ver> subtree (e.g. mw-config).
            $table->string('core_version')->nullable();

            // Path relative to the MediaWiki root, resolved once at
            // registration time so the layout is auditable rather than
            // recomputed (and possibly drifting) on every deploy.
            $table->string('path');

            // Extensions/skins the farm actually enables, per mw-config. Purely
            // informational; drives the "in use" filter on the repo browser.
            $table->boolean('in_use')->default(false);

            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();

            $table->unique(['type', 'name', 'core_version']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};
