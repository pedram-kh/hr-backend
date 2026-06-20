<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ProvinceSeeder::class,
            DocumentTypeSeeder::class,
            TopicSeeder::class,
            RoleSeeder::class,
            TestUserSeeder::class, // depends on provinces + roles above
        ]);
    }
}
