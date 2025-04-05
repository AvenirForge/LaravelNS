<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Faker\Factory as Faker;


class UserSeeder extends Seeder
{

    public function run()
    {
        // Użycie Faker do generowania danych
        $faker = Faker::create();

        // Tworzenie 50 użytkowników
        for ($i = 0; $i < 50; $i++) {
            User::create([
                'name' => $faker->name,  // Generowanie losowego imienia
                'email' => $faker->unique()->safeEmail,  // Generowanie unikalnego emaila
                'password' => Hash::make('password123'),  // Hasło użytkownika
                'avatar' => 'avatars/default.png',  // Ustawienie domyślnego avataru
            ]);
        }

        // Dodajemy przykładowego admina
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('password123'),
            'avatar' => 'avatars/default.png',  // Ustawienie domyślnego avataru
        ]);
    }
}
