<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A MediaWiki core version as a first-class thing, i.e. one versions/<ver>
     * subtree.
     *
     * It needs to exist independently of the repositories inside it so that
     * "create 1.46 from 1.45" and "undeploy 1.46" have something to hang off,
     * and so history can say which version a deployment brought into being.
     */
    public function up(): void
    {
        Schema::create('mediawiki_versions', function (Blueprint $table) {
            $table->id();

            // '1.45'. This becomes a directory name, so it is validated hard.
            $table->string('version')->unique();

            // active|undeployed. Undeployed versions are kept, not deleted: past
            // deployments reference them and history has to keep resolving.
            $table->string('status')->default('active');

            // Which version this one was reconstructed from, for the audit trail.
            $table->foreignId('created_from_id')->nullable()
                ->constrained('mediawiki_versions')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('undeployed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mediawiki_versions');
    }
};
