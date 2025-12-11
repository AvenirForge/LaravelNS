<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Faker\Factory as FakerFactory;

class DatabaseSeeder extends Seeder
{
    // Definicje plików źródłowych
    private array $sourceFiles = [
        'avatars_users' => ['1.avif', '2.avif', '3.avif', '4.avif'],
        'avatars_courses' => ['1.avif', '2.avif', '3.avif', '4.avif'],
        'note_docs' => ['1.pdf', '2.pdf', '3.pdf', '4.pdf'],
        'note_imgs' => ['1.avif', '2.avif', '3.avif', '4.avif', '5.avif'],
    ];

    public function run(): void
    {
        // Inicjalizacja generatora danych w języku polskim
        $faker = FakerFactory::create('pl_PL');

        $this->cleanStorage();
        $this->truncateTables();

        $userIds = collect();
        $now = now();

        // 1. Administrator
        $adminId = DB::table('users')->insertGetId([
            'name' => 'Administrator Systemu',
            'email' => 'admin@notesync.pl',
            'password' => Hash::make('password'),
            'email_verified_at' => $now,
            'avatar' => $this->storeFile('users/avatars', 'avatars_users'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $userIds->push($adminId);

        // 2. Użytkownicy Demo (15 kont)
        for ($i = 1; $i <= 15; $i++) {
            $id = DB::table('users')->insertGetId([
                'name' => "Użytkownik Demo {$i}",
                'email' => "demo{$i}@notesync.pl",
                'password' => Hash::make((string)$i), // Hasło to numer (1, 2, 3...)
                'email_verified_at' => $now,
                'avatar' => $this->storeFile('users/avatars', 'avatars_users'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $userIds->push($id);
        }

        // 3. Losowi użytkownicy (polskie imiona i nazwiska)
        for ($i = 0; $i < 20; $i++) {
            $id = DB::table('users')->insertGetId([
                'name' => $faker->name(), // np. Jan Kowalski
                'email' => $faker->unique()->safeEmail(),
                'password' => Hash::make('password'),
                'email_verified_at' => $now,
                'avatar' => $this->storeFile('users/avatars', 'avatars_users'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $userIds->push($id);
        }

        // 4. Generowanie Kursów i ich zawartości (Notatki/Testy)
        for ($i = 0; $i < 20; $i++) {
            $this->seedCourse($userIds, $faker);
        }

        // 5. Generowanie Zaproszeń
        $this->seedInvitations($userIds, $faker);
    }

    // --- Metody Pomocnicze ---

    private function cleanStorage(): void
    {
        $disk = Storage::disk('public');
        $disk->deleteDirectory('users/avatars');
        $disk->deleteDirectory('courses/avatars');
        $disk->deleteDirectory('notes/files');

        $disk->makeDirectory('users/avatars');
        $disk->makeDirectory('courses/avatars');
        $disk->makeDirectory('notes/files');
    }

    private function truncateTables(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        DB::table('invitations')->truncate();
        DB::table('course_test')->truncate();
        DB::table('course_note')->truncate();
        DB::table('courses_users')->truncate();
        DB::table('tests_answers')->truncate();
        DB::table('tests_questions')->truncate();
        DB::table('note_files')->truncate();
        DB::table('tests')->truncate();
        DB::table('notes')->truncate();
        DB::table('courses')->truncate();
        DB::table('users')->truncate();

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }

    private function seedCourse($userIds, $faker): void
    {
        $ownerId = $userIds->random();
        // Kursy tworzone w ostatnich 4 miesiącach
        $courseDate = $faker->dateTimeBetween('-4 months', '-1 week');

        // Generowanie polskich tytułów kursów
        $prefixes = ['Kurs:', 'Wstęp do:', 'Zaawansowany:', 'Warsztaty:', 'Podstawy:', 'Masterclass:'];
        $subjects = ['Programowania', 'Grafiki', 'Zarządzania', 'Psychologii', 'Marketingu', 'Fotografii', 'Excela', 'Reacta', 'Laravela'];
        $title = $faker->randomElement($prefixes) . ' ' . $faker->randomElement($subjects) . ' - ' . ucfirst($faker->word());

        $courseId = DB::table('courses')->insertGetId([
            'user_id' => $ownerId,
            'title' => $title,
            'description' => $faker->realText(300), // Polski tekst
            'avatar' => $this->storeFile('courses/avatars', 'avatars_courses'),
            'type' => $faker->boolean(70) ? 'public' : 'private',
            'created_at' => $courseDate,
            'updated_at' => $courseDate,
        ]);

        // Właściciel kursu
        DB::table('courses_users')->insert([
            'course_id' => $courseId,
            'user_id' => $ownerId,
            'role' => 'owner',
            'status' => 'accepted',
            'created_at' => $courseDate,
            'updated_at' => $courseDate,
        ]);

        // Dodawanie członków (wykluczając właściciela)
        $membersCount = rand(3, 10);
        $potentialMembers = $userIds->reject(fn($id) => $id === $ownerId)
            ->shuffle()
            ->take($membersCount);

        foreach ($potentialMembers as $memberId) {
            $joinDate = $faker->dateTimeBetween($courseDate, 'now');
            DB::table('courses_users')->insert([
                'course_id' => $courseId,
                'user_id' => $memberId,
                'role' => $faker->randomElement(['admin', 'moderator', 'member', 'member']),
                'status' => 'accepted',
                'created_at' => $joinDate,
                'updated_at' => $joinDate,
            ]);
        }

        // Pula użytkowników mogących tworzyć treści w kursie
        $allowedUserIds = $potentialMembers->push($ownerId);

        // Generowanie aktywności (mieszanka notatek i testów)
        $this->seedActivities($courseId, $allowedUserIds, $faker, $courseDate);
    }

    private function seedActivities(int $courseId, $allowedUserIds, $faker, $startDate): void
    {
        // Generujemy od 8 do 15 aktywności na kurs
        $count = rand(8, 15);

        for ($i = 0; $i < $count; $i++) {
            // Data aktywności od momentu powstania kursu do teraz
            $activityDate = $faker->dateTimeBetween($startDate, 'now');

            // 60% szans na notatkę, 40% na test
            if ($faker->boolean(60)) {
                $this->createSingleNote($courseId, $allowedUserIds, $faker, $activityDate);
            } else {
                $this->createSingleTest($courseId, $allowedUserIds, $faker, $activityDate);
            }
        }
    }

    private function createSingleNote(int $courseId, $allowedUserIds, $faker, $date): void
    {
        $authorId = $allowedUserIds->random();

        $prefixes = ['Notatka:', 'Podsumowanie:', 'Wykład:', 'Materiały:', 'Lista zadań:', 'Projekt:'];
        $title = $faker->randomElement($prefixes) . ' ' . $faker->words(3, true);

        $noteId = DB::table('notes')->insertGetId([
            'user_id' => $authorId,
            'title' => ucfirst($title),
            'description' => $faker->realText(500),
            'is_private' => $faker->boolean(20) ? 1 : 0,
            'created_at' => $date,
            'updated_at' => $date,
        ]);

        DB::table('course_note')->insert([
            'course_id' => $courseId,
            'note_id' => $noteId,
        ]);

        // Dodawanie plików (PDF i Obrazy)
        $filesCount = rand(1, 4); // 1-4 pliki na notatkę
        for ($k = 0; $k < $filesCount; $k++) {
            // 40% szans na dokument PDF, 60% na obrazek
            if ($faker->boolean(40)) {
                $this->createNoteFile($noteId, 'notes/documents', 'note_docs', 'application/pdf', $k, $date);
            } else {
                $this->createNoteFile($noteId, 'notes/images', 'note_imgs', 'image/avif', $k, $date);
            }
        }
    }

    private function createSingleTest(int $courseId, $allowedUserIds, $faker, $date): void
    {
        $authorId = $allowedUserIds->random();

        $prefixes = ['Sprawdzian:', 'Quiz:', 'Egzamin:', 'Test wiedzy:', 'Kartkówka:'];
        $title = $faker->randomElement($prefixes) . ' ' . $faker->words(2, true);

        $testId = DB::table('tests')->insertGetId([
            'user_id' => $authorId,
            'title' => ucfirst($title),
            'description' => $faker->realText(200),
            'status' => $faker->randomElement(['public', 'private']),
            'created_at' => $date,
            'updated_at' => $date,
        ]);

        DB::table('course_test')->insert([
            'course_id' => $courseId,
            'test_id' => $testId,
        ]);

        // Pytania i odpowiedzi po polsku
        $questionsCount = rand(4, 8);
        for ($q = 1; $q <= $questionsCount; $q++) {
            $questionId = DB::table('tests_questions')->insertGetId([
                'test_id' => $testId,
                'question' => $faker->realText(50) . '?',
                'created_at' => $date,
                'updated_at' => $date,
            ]);

            $answersCount = rand(2, 4);
            $hasCorrect = false;

            for ($a = 0; $a < $answersCount; $a++) {
                $isCorrect = false;
                // Zapewniamy, że ostatnia odpowiedź jest poprawna jeśli wcześniej nie było poprawnej
                if (!$hasCorrect && ($a === $answersCount - 1 || $faker->boolean(30))) {
                    $isCorrect = true;
                    $hasCorrect = true;
                }

                DB::table('tests_answers')->insert([
                    'question_id' => $questionId,
                    'answer' => ucfirst($faker->words(3, true)),
                    'is_correct' => $isCorrect ? 1 : 0,
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);
            }

            // Fallback: jeśli losowo nie wybrano poprawnej, ustaw losową na poprawną
            if (!$hasCorrect) {
                DB::table('tests_answers')
                    ->where('question_id', $questionId)
                    ->inRandomOrder()
                    ->limit(1)
                    ->update(['is_correct' => 1]);
            }
        }
    }

    private function seedInvitations($userIds, $faker): void
    {
        $courses = DB::table('courses')->pluck('id');
        if ($courses->isEmpty()) return;

        for ($i = 0; $i < 30; $i++) {
            $inviterId = $userIds->random();
            $courseId = $courses->random();
            $date = $faker->dateTimeBetween('-1 month', 'now');

            DB::table('invitations')->insert([
                'inviter_id' => $inviterId,
                'course_id' => $courseId,
                'invited_email' => $faker->email(),
                'token' => Str::random(32),
                'role' => $faker->randomElement(['member', 'moderator']),
                'status' => 'pending',
                'expires_at' => now()->addDays(7),
                'created_at' => $date,
                'updated_at' => $date,
            ]);
        }
    }

    private function storeFile(string $targetDir, string $sourceKey): ?string
    {
        if (!isset($this->sourceFiles[$sourceKey])) return null;

        $files = $this->sourceFiles[$sourceKey];
        $filename = $files[array_rand($files)];

        $srcDirMap = [
            'avatars_users' => 'avatars/users',
            'avatars_courses' => 'avatars/courses',
        ];

        $sourceSubDir = $srcDirMap[$sourceKey] ?? $targetDir;
        $fullSourcePath = database_path("seeders/data/{$sourceSubDir}/{$filename}");

        if (!File::exists($fullSourcePath)) {
            return null;
        }

        $extension = File::extension($fullSourcePath);
        $newFilename = Str::random(20) . '.' . $extension;
        $storagePath = "{$targetDir}/{$newFilename}";

        Storage::disk('public')->put($storagePath, File::get($fullSourcePath));

        return $storagePath;
    }

    private function createNoteFile(int $noteId, string $sourceSubDir, string $sourceKey, string $mimeType, int $order, $date): void
    {
        if (!isset($this->sourceFiles[$sourceKey])) return;

        $files = $this->sourceFiles[$sourceKey];
        $filename = $files[array_rand($files)];
        $fullSourcePath = database_path("seeders/data/{$sourceSubDir}/{$filename}");

        if (File::exists($fullSourcePath)) {
            $newFilename = Str::random(20) . '_' . $filename;
            $relativePath = "notes/files/{$newFilename}";

            Storage::disk('public')->put($relativePath, File::get($fullSourcePath));

            DB::table('note_files')->insert([
                'note_id' => $noteId,
                'file_path' => $relativePath,
                'original_name' => $filename,
                'mime_type' => $mimeType,
                'order' => $order,
                'created_at' => $date,
                'updated_at' => $date,
            ]);
        }
    }
}
