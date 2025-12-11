<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    private array $sourceFiles = [
        'avatars_users' => ['1.avif', '2.avif', '3.avif', '4.avif'],
        'avatars_courses' => ['1.avif', '2.avif', '3.avif', '4.avif'],
        'note_docs' => ['1.pdf', '2.pdf', '3.pdf', '4.pdf'],
        'note_imgs' => ['1.avif', '2.avif', '3.avif', '4.avif', '5.avif'],
    ];

    public function run(): void
    {
        $this->cleanStorage();
        $this->truncateTables();

        $userIds = collect();
        $now = now();

        $adminId = DB::table('users')->insertGetId([
            'name' => 'Admin Demo',
            'email' => 'admin@notesync.pl',
            'password' => Hash::make('password'),
            'email_verified_at' => $now,
            'avatar' => $this->storeFile('users/avatars', 'avatars_users'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $userIds->push($adminId);

        for ($i = 1; $i <= 15; $i++) {
            $id = DB::table('users')->insertGetId([
                'name' => "Demo User {$i}",
                'email' => "demo{$i}",
                'password' => Hash::make((string)$i),
                'email_verified_at' => $now,
                'avatar' => $this->storeFile('users/avatars', 'avatars_users'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $userIds->push($id);
        }

        for ($i = 0; $i < 20; $i++) {
            $id = DB::table('users')->insertGetId([
                'name' => fake()->name(),
                'email' => fake()->unique()->safeEmail(),
                'password' => Hash::make('password'),
                'email_verified_at' => $now,
                'avatar' => $this->storeFile('users/avatars', 'avatars_users'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $userIds->push($id);
        }

        for ($i = 0; $i < 20; $i++) {
            $this->seedCourse($userIds);
        }

        $this->seedInvitations($userIds);
    }

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

    private function seedCourse($userIds): void
    {
        $ownerId = $userIds->random();
        $now = now();

        $courseId = DB::table('courses')->insertGetId([
            'user_id' => $ownerId,
            'title' => fake()->company() . ' ' . fake()->word(),
            'description' => fake()->paragraph(),
            'avatar' => $this->storeFile('courses/avatars', 'avatars_courses'),
            'type' => fake()->boolean(70) ? 'public' : 'private',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('courses_users')->insert([
            'course_id' => $courseId,
            'user_id' => $ownerId,
            'role' => 'owner',
            'status' => 'accepted',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $membersCount = rand(3, 10);

        $potentialMembers = $userIds->reject(fn($id) => $id === $ownerId)
            ->shuffle()
            ->take($membersCount);

        foreach ($potentialMembers as $memberId) {
            DB::table('courses_users')->insert([
                'course_id' => $courseId,
                'user_id' => $memberId,
                'role' => fake()->randomElement(['admin', 'moderator', 'member', 'member']),
                'status' => 'accepted',
                'created_at' => fake()->dateTimeBetween('-1 month'),
                'updated_at' => $now,
            ]);
        }

        $allowedUserIds = $potentialMembers->push($ownerId);
        $this->seedNotes($courseId, $allowedUserIds);
        $this->seedTests($courseId, $allowedUserIds);
    }

    private function seedNotes(int $courseId, $allowedUserIds): void
    {
        $notesCount = rand(3, 8);
        $now = now();

        for ($j = 0; $j < $notesCount; $j++) {
            $authorId = $allowedUserIds->random();

            $noteId = DB::table('notes')->insertGetId([
                'user_id' => $authorId,
                'title' => fake()->sentence(4),
                'description' => fake()->paragraph(),
                'is_private' => fake()->boolean(20) ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('course_note')->insert([
                'course_id' => $courseId,
                'note_id' => $noteId,
            ]);

            $filesCount = rand(1, 4);
            for ($k = 0; $k < $filesCount; $k++) {
                $isImage = fake()->boolean(60);
                if ($isImage) {
                    $this->createNoteFile($noteId, 'notes/images', 'note_imgs', 'image/avif', $k);
                } else {
                    $this->createNoteFile($noteId, 'notes/documents', 'note_docs', 'application/pdf', $k);
                }
            }
        }
    }

    private function seedTests(int $courseId, $allowedUserIds): void
    {
        $testsCount = rand(2, 5);
        $now = now();

        for ($t = 0; $t < $testsCount; $t++) {
            $authorId = $allowedUserIds->random();

            $testId = DB::table('tests')->insertGetId([
                'user_id' => $authorId,
                'title' => 'Test: ' . fake()->bs(),
                'description' => fake()->text(150),
                'status' => fake()->randomElement(['public', 'private']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('course_test')->insert([
                'course_id' => $courseId,
                'test_id' => $testId,
            ]);

            $questionsCount = rand(4, 10);
            for ($q = 1; $q <= $questionsCount; $q++) {
                $questionId = DB::table('tests_questions')->insertGetId([
                    'test_id' => $testId,
                    'question' => fake()->sentence() . '?',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $answersCount = rand(2, 4);
                $hasCorrect = false;

                for ($a = 0; $a < $answersCount; $a++) {
                    $isCorrect = false;
                    if (!$hasCorrect && ($a === $answersCount - 1 || fake()->boolean(30))) {
                        $isCorrect = true;
                        $hasCorrect = true;
                    }

                    DB::table('tests_answers')->insert([
                        'question_id' => $questionId,
                        'answer' => fake()->sentence(3),
                        'is_correct' => $isCorrect ? 1 : 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                if (!$hasCorrect) {
                    DB::table('tests_answers')
                        ->where('question_id', $questionId)
                        ->inRandomOrder()
                        ->limit(1)
                        ->update(['is_correct' => 1]);
                }
            }
        }
    }

    private function seedInvitations($userIds): void
    {
        $courses = DB::table('courses')->pluck('id');
        if ($courses->isEmpty()) return;
        $now = now();

        for ($i = 0; $i < 30; $i++) {
            $inviterId = $userIds->random();
            $courseId = $courses->random();

            DB::table('invitations')->insert([
                'inviter_id' => $inviterId,
                'course_id' => $courseId,
                'invited_email' => fake()->email(),
                'token' => Str::random(32),
                'role' => fake()->randomElement(['member', 'moderator']),
                'status' => 'pending',
                'expires_at' => now()->addDays(7),
                'created_at' => $now,
                'updated_at' => $now,
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

    private function createNoteFile(int $noteId, string $sourceSubDir, string $sourceKey, string $mimeType, int $order): void
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
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
