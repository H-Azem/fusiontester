<?php

namespace Database\Seeders;

use App\Actions\Auth\EnsureDefaultAdminAction;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        run(new EnsureDefaultAdminAction);
    }
}
