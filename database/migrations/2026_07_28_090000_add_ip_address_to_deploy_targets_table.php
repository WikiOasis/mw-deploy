<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deploy_targets', function (Blueprint $table) {
            // The canary check pins the vhost it's testing to this address with
            // curl --resolve, so it exercises *this* box rather than whatever the
            // proxy would otherwise have picked. Without it the check falls back to
            // 127.0.0.1, which only works when the appserver's web server happens
            // to listen on loopback.
            $table->string('ip_address')->nullable()->after('hostname');
        });
    }

    public function down(): void
    {
        Schema::table('deploy_targets', function (Blueprint $table) {
            $table->dropColumn('ip_address');
        });
    }
};
