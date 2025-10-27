<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Kolejność ma znaczenie – najpierw użytkownicy, potem notatki
//        $this->call([
//            UserSeeder::class,
//            ]);
    }
}
