<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Note;
use Illuminate\Database\Seeder;

class NoteSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Pobranie kilku użytkowników do przypisania notatek
        $users = User::all();

        // Sprawdzenie, czy użytkownicy są dostępni
        if ($users->isEmpty()) {
            // Jeśli brak użytkowników, wstawimy tymczasowego
            $user = User::factory()->create();
        } else {
            // Przypisanie notatek do losowych użytkowników
            $user = $users->random();
        }

        for ($i = 0; $i < 10000; $i++) {
            Note::create([
                'title' => 'Sample Note ' . ($i) ,
                'description' => 'This is a description for the' .($i) . ' sample note. It is shared with the public.',
                'is_private' => true,
                'user_id' => rand(609, 1209),
                'file_path' => 'storage/files/sample'.($i).'.jpg',
            ]);
        }


        // Można również dodać inne użytkowników i notatki w pętli
        foreach ($users as $user) {
            foreach (range(1, 3) as $index) {
                Note::create([
                    'title' => 'User ' . $user->name . ' Note ' . $index,
                    'description' => 'This note belongs to ' . $user->name,
                    'is_private' => rand(0, 1) == 1 ? true : false,
                    'user_id' => $user->id,
                    'file_path' => 'storage/files/sample' . $index . '.pdf',
                ]);
            }
        }
    }
}
