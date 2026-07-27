<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Roles and permissions are structural, not sample data: this always
        // runs. Repositories, targets and users are environment-specific and are
        // created through the UI or `php artisan mwdeploy:create-admin`.
        $this->call(RolesAndPermissionsSeeder::class);
    }
}
