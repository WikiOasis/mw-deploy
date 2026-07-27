<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deploy_targets', function (Blueprint $table) {
            $table->id();

            // Must match the Salt minion id exactly — this string is what gets
            // passed as the target to `salt '<hostname>' cmd.run ...`.
            $table->string('hostname')->unique();

            $table->string('role'); // appserver|proxy|staging

            // For proxies: the HAProxy backend to depool/repool against.
            $table->string('haproxy_backend')->nullable();

            // For appservers: the server label HAProxy knows them by, which is
            // not always the minion id.
            $table->string('haproxy_server_name')->nullable();

            $table->string('canary_vhost')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deploy_targets');
    }
};
