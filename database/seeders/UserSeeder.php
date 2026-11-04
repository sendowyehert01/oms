<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (config('auth.bootstrap_admin.email')) {
            Artisan::call('oms:create-admin', ['--from-env' => true, '--no-interaction' => true]);
        }
    }
}
