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
    private array $sourceFiles = [
        'avatars_users' => ['1.avif', '2.avif', '3.avif', '4.avif'],
        'avatars_courses' => ['1.avif', '2.avif', '3.avif', '4.avif'],
        'note_docs' => ['1.pdf', '2.pdf', '3.pdf', '4.pdf'],
        'note_imgs' => [
            '1.avif', '2.avif', '3.avif', '4.avif', '5.avif', '6.avif', '7.avif', '8.avif', '9.avif', '10.avif',
            '11.avif', '12.avif', '13.avif', '14.avif', '15.avif', '16.avif', '17.avif', '18.avif', '19.avif', '20.avif',
            '21.avif', '22.avif', '23.avif', '24.avif', '25.avif', '26.avif', '27.avif', '28.avif'
        ],
    ];

    private array $subjects = [
        'Informatyka', 'Matematyka', 'Historia', 'Biologia', 'Fizyka', 'Geografia', 'Literatura', 'Chemia'
    ];

    public function run(): void
    {
        $faker = FakerFactory::create('pl_PL');

        $this->cleanStorage();
        $this->truncateTables();

        $userIds = collect();
        $now = now();

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

        for ($i = 1; $i <= 15; $i++) {
            $id = DB::table('users')->insertGetId([
                'name' => "Użytkownik Demo {$i}",
                'email' => "demo{$i}@notesync.pl",
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
                'name' => $faker->firstName . ' ' . $faker->lastName,
                'email' => $faker->unique()->safeEmail(),
                'password' => Hash::make('password'),
                'email_verified_at' => $now,
                'avatar' => $this->storeFile('users/avatars', 'avatars_users'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $userIds->push($id);
        }

        for ($i = 0; $i < 20; $i++) {
            $this->seedCourse($userIds, $faker);
        }

        $this->seedInvitations($userIds, $faker);
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

    private function seedCourse($userIds, $faker): void
    {
        $ownerId = $userIds->random();
        $courseDate = $faker->dateTimeBetween('-4 months', '-1 week');

        $subject = $faker->randomElement($this->subjects);
        $prefixes = ['Wstęp do:', 'Zaawansowana:', 'Podstawy:', 'Teoria:', 'Warsztaty:', 'Seminarium:'];
        $title = $faker->randomElement($prefixes) . ' ' . $subject . ' - ' . $faker->year;

        $courseId = DB::table('courses')->insertGetId([
            'user_id' => $ownerId,
            'title' => $title,
            'description' => $faker->realText(300),
            'avatar' => $this->storeFile('courses/avatars', 'avatars_courses'),
            'type' => $faker->boolean(70) ? 'public' : 'private',
            'created_at' => $courseDate,
            'updated_at' => $courseDate,
        ]);

        DB::table('courses_users')->insert([
            'course_id' => $courseId,
            'user_id' => $ownerId,
            'role' => 'owner',
            'status' => 'accepted',
            'created_at' => $courseDate,
            'updated_at' => $courseDate,
        ]);

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

        $allowedUserIds = $potentialMembers->push($ownerId);
        $this->seedActivities($courseId, $allowedUserIds, $faker, $courseDate, $subject);
    }

    private function seedActivities(int $courseId, $allowedUserIds, $faker, $startDate, string $subject): void
    {
        $count = rand(8, 15);

        for ($i = 0; $i < $count; $i++) {
            $activityDate = $faker->dateTimeBetween($startDate, 'now');

            if ($faker->boolean(60)) {
                $this->createSingleNote($courseId, $allowedUserIds, $faker, $activityDate);
            } else {
                $this->createSingleTest($courseId, $allowedUserIds, $faker, $activityDate, $subject);
            }
        }
    }

    private function createSingleNote(int $courseId, $allowedUserIds, $faker, $date): void
    {
        $authorId = $allowedUserIds->random();

        $prefixes = ['Wykład:', 'Skrypt:', 'Lista zagadnień:', 'Notatki z zajęć:', 'Projekt zaliczeniowy:'];
        $title = $faker->randomElement($prefixes) . ' ' . $date->format('d.m.Y');

        $noteId = DB::table('notes')->insertGetId([
            'user_id' => $authorId,
            'title' => $title,
            'description' => $faker->realText(500),
            'is_private' => $faker->boolean(20) ? 1 : 0,
            'created_at' => $date,
            'updated_at' => $date,
        ]);

        DB::table('course_note')->insert([
            'course_id' => $courseId,
            'note_id' => $noteId,
        ]);

        $filesCount = rand(1, 4);
        for ($k = 0; $k < $filesCount; $k++) {
            if ($faker->boolean(40)) {
                $this->createNoteFile($noteId, 'notes/documents', 'note_docs', 'application/pdf', $k, $date);
            } else {
                $this->createNoteFile($noteId, 'notes/images', 'note_imgs', 'image/avif', $k, $date);
            }
        }
    }

    private function createSingleTest(int $courseId, $allowedUserIds, $faker, $date, string $subject): void
    {
        $authorId = $allowedUserIds->random();

        $testNames = [
            'Kolokwium zaliczeniowe', 'Egzamin połówkowy', 'Quiz sprawdzający wiedzę',
            'Kartkówka wejściowa', 'Test semestralny', 'Sprawdzian wiedzy praktycznej'
        ];
        $title = $faker->randomElement($testNames) . ': ' . $subject;

        $testId = DB::table('tests')->insertGetId([
            'user_id' => $authorId,
            'title' => $title,
            'description' => 'Test weryfikujący wiedzę z zakresu przedmiotu: ' . $subject . '. Czas trwania: 45 min.',
            'status' => $faker->randomElement(['public', 'private']),
            'created_at' => $date,
            'updated_at' => $date,
        ]);

        DB::table('course_test')->insert([
            'course_id' => $courseId,
            'test_id' => $testId,
        ]);

        $questionsPool = $this->getQuizData($subject);
        $selectedQuestions = collect($questionsPool)->shuffle()->take(rand(5, 8));

        foreach ($selectedQuestions as $qData) {
            $questionId = DB::table('tests_questions')->insertGetId([
                'test_id' => $testId,
                'question' => $qData['q'],
                'created_at' => $date,
                'updated_at' => $date,
            ]);

            $answers = $qData['a'];
            $correctIndex = $qData['c'];

            foreach ($answers as $index => $answerText) {
                DB::table('tests_answers')->insert([
                    'question_id' => $questionId,
                    'answer' => $answerText,
                    'is_correct' => ($index === $correctIndex) ? 1 : 0,
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);
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

    private function getQuizData(string $subject): array
    {
        $db = [
            'Informatyka' => [
                ['q' => 'Co oznacza skrót CPU?', 'a' => ['Central Processing Unit', 'Central Process Utility', 'Computer Personal Unit', 'Control Processing Unit'], 'c' => 0],
                ['q' => 'Który język jest używany do stylizacji stron WWW?', 'a' => ['HTML', 'CSS', 'Python', 'Java'], 'c' => 1],
                ['q' => 'Jaka jest złożoność obliczeniowa wyszukiwania binarnego?', 'a' => ['O(n)', 'O(n^2)', 'O(log n)', 'O(1)'], 'c' => 2],
                ['q' => 'Co to jest SQL?', 'a' => ['Język programowania gier', 'System operacyjny', 'Structured Query Language', 'Protokół sieciowy'], 'c' => 2],
                ['q' => 'Adres IP w wersji 4 składa się z ilu bitów?', 'a' => ['128', '64', '32', '16'], 'c' => 2],
                ['q' => 'Która struktura danych działa na zasadzie LIFO?', 'a' => ['Kolejka', 'Stos', 'Drzewo', 'Graf'], 'c' => 1],
                ['q' => 'Wzorzec projektowy Singleton gwarantuje, że klasa ma:', 'a' => ['Wiele instancji', 'Tylko jedną instancję', 'Brak instancji', 'Instancje statyczne'], 'c' => 1],
                ['q' => 'Co oznacza akronim HTTP?', 'a' => ['HyperText Transfer Protocol', 'High Transfer Text Protocol', 'Hyper Transfer Text Program', 'Hyper Tool Transfer Protocol'], 'c' => 0],
            ],
            'Matematyka' => [
                ['q' => 'Ile wynosi pierwiastek kwadratowy z 144?', 'a' => ['10', '11', '12', '13'], 'c' => 2],
                ['q' => 'Jak nazywa się wynik dodawania?', 'a' => ['Iloczyn', 'Iloraz', 'Suma', 'Różnica'], 'c' => 2],
                ['q' => 'Ile stopni ma kąt prosty?', 'a' => ['45', '60', '90', '180'], 'c' => 2],
                ['q' => 'Jaka jest pochodna z x^2?', 'a' => ['x', '2x', 'x^2', '2'], 'c' => 1],
                ['q' => 'Twierdzenie Pitagorasa dotyczy trójkąta:', 'a' => ['Równobocznego', 'Prostokątnego', 'Rozwartokątnego', 'Równoramiennego'], 'c' => 1],
                ['q' => 'Liczba Pi w przybliżeniu to:', 'a' => ['3.12', '3.14', '3.16', '3.18'], 'c' => 1],
                ['q' => 'Co jest wykresem funkcji kwadratowej?', 'a' => ['Prosta', 'Hiperbola', 'Parabola', 'Elipsa'], 'c' => 2],
                ['q' => 'Ile wynosi silnia z 3 (3!)?', 'a' => ['3', '6', '9', '1'], 'c' => 1],
            ],
            'Historia' => [
                ['q' => 'W którym roku odbyła się Bitwa pod Grunwaldem?', 'a' => ['1410', '1525', '966', '1939'], 'c' => 0],
                ['q' => 'Kto był pierwszym królem Polski?', 'a' => ['Mieszko I', 'Bolesław Chrobry', 'Kazimierz Wielki', 'Władysław Jagiełło'], 'c' => 1],
                ['q' => 'Kiedy wybuchła II wojna światowa?', 'a' => ['1914', '1939', '1945', '1920'], 'c' => 1],
                ['q' => 'Gdzie podpisano unię polsko-litewską w 1569 roku?', 'a' => ['W Krakowie', 'W Wilnie', 'W Lublinie', 'W Gnieźnie'], 'c' => 2],
                ['q' => 'Kto dowodził Legionami Polskimi we Włoszech?', 'a' => ['Tadeusz Kościuszko', 'Jan Henryk Dąbrowski', 'Józef Piłsudski', 'Józef Poniatowski'], 'c' => 1],
                ['q' => 'W którym roku upadł Mur Berliński?', 'a' => ['1980', '1989', '1991', '1995'], 'c' => 1],
                ['q' => 'Stolicą Polski przed Warszawą był:', 'a' => ['Gdańsk', 'Poznań', 'Kraków', 'Wrocław'], 'c' => 2],
                ['q' => 'Kto odkrył Amerykę w 1492 roku?', 'a' => ['Vasco da Gama', 'Ferdynand Magellan', 'Krzysztof Kolumb', 'Amerigo Vespucci'], 'c' => 2],
            ],
            'Biologia' => [
                ['q' => 'Co jest nośnikiem informacji genetycznej?', 'a' => ['RNA', 'DNA', 'ATP', 'Białko'], 'c' => 1],
                ['q' => 'Jak nazywa się proces tworzenia pokarmu przez rośliny?', 'a' => ['Oddychanie', 'Fotosynteza', 'Fermentacja', 'Transpiracja'], 'c' => 1],
                ['q' => 'Który organ odpowiada za pompowanie krwi?', 'a' => ['Wątroba', 'Płuca', 'Serce', 'Nerki'], 'c' => 2],
                ['q' => 'Ile par chromosomów ma zdrowy człowiek?', 'a' => ['21', '22', '23', '24'], 'c' => 2],
                ['q' => 'Podstawowa jednostka budulcowa organizmu to:', 'a' => ['Tkanka', 'Komórka', 'Narząd', 'Układ'], 'c' => 1],
                ['q' => 'Mitochondrium jest nazywane:', 'a' => ['Mózgiem komórki', 'Centrum energetycznym', 'Magazynem wody', 'Fabryką białek'], 'c' => 1],
                ['q' => 'Grzyby to organizmy:', 'a' => ['Samożywne', 'Cudzożywne', 'Autotroficzne', 'Chemosyntetyzujące'], 'c' => 1],
                ['q' => 'Która witamina jest produkowana w skórze pod wpływem słońca?', 'a' => ['Witamina A', 'Witamina C', 'Witamina D', 'Witamina K'], 'c' => 2],
            ],
            'Fizyka' => [
                ['q' => 'Jaka jest jednostka siły w układzie SI?', 'a' => ['Dżul', 'Wat', 'Niuton', 'Pascal'], 'c' => 2],
                ['q' => 'Wzór E=mc^2 został sformułowany przez:', 'a' => ['Isaaca Newtona', 'Alberta Einsteina', 'Nielsa Bohra', 'Marię Skłodowską-Curie'], 'c' => 1],
                ['q' => 'Prędkość światła w próżni wynosi około:', 'a' => ['300 000 km/s', '340 m/s', '1000 km/h', '30 000 km/s'], 'c' => 0],
                ['q' => 'Co mierzymy w Amperach?', 'a' => ['Napięcie', 'Opór', 'Natężenie prądu', 'Moc'], 'c' => 2],
                ['q' => 'Pierwsza zasada dynamiki Newtona to zasada:', 'a' => ['Bezwładności', 'Akcji i reakcji', 'Grawitacji', 'Zachowania pędu'], 'c' => 0],
                ['q' => 'Stan skupienia, w którym cząsteczki są najbliżej siebie to:', 'a' => ['Gaz', 'Ciecz', 'Ciało stałe', 'Plazma'], 'c' => 2],
                ['q' => 'Przyciąganie ziemskie to inaczej:', 'a' => ['Magnetyzm', 'Grawitacja', 'Elektrostatyka', 'Tarcie'], 'c' => 1],
                ['q' => 'Urządzenie do pomiaru ciśnienia atmosferycznego to:', 'a' => ['Termometr', 'Barometr', 'Higrometr', 'Anemometr'], 'c' => 1],
            ],
            'Geografia' => [
                ['q' => 'Jaki jest największy kontynent na świecie?', 'a' => ['Afryka', 'Ameryka Północna', 'Azja', 'Europa'], 'c' => 2],
                ['q' => 'Stolicą Francji jest:', 'a' => ['Lyon', 'Marsylia', 'Paryż', 'Bordeaux'], 'c' => 2],
                ['q' => 'Najdłuższa rzeka w Polsce to:', 'a' => ['Odra', 'Warta', 'Wisła', 'Bug'], 'c' => 2],
                ['q' => 'Mount Everest leży w paśmie:', 'a' => ['Alp', 'Andów', 'Himalajów', 'Karpat'], 'c' => 2],
                ['q' => 'Który ocean jest największy?', 'a' => ['Atlantycki', 'Indyjski', 'Spokojny', 'Arktyczny'], 'c' => 2],
                ['q' => 'Pustynia Sahara znajduje się w:', 'a' => ['Azji', 'Ameryce Południowej', 'Afryce', 'Australii'], 'c' => 2],
                ['q' => 'Ile województw ma Polska?', 'a' => ['14', '15', '16', '17'], 'c' => 2],
                ['q' => 'Równik dzieli Ziemię na półkule:', 'a' => ['Wschodnią i Zachodnią', 'Północną i Południową', 'Lądową i Wodną', 'Zimną i Ciepłą'], 'c' => 1],
            ],
            'Literatura' => [
                ['q' => 'Kto napisał "Pana Tadeusza"?', 'a' => ['Juliusz Słowacki', 'Adam Mickiewicz', 'Cyprian Kamil Norwid', 'Bolesław Prus'], 'c' => 1],
                ['q' => 'Główny bohater "Zbrodni i kary" to:', 'a' => ['Raskolnikow', 'Wokulski', 'Kmicic', 'Werter'], 'c' => 0],
                ['q' => 'Epoka literacka po Oświeceniu to:', 'a' => ['Renesans', 'Barok', 'Romantyzm', 'Pozytywizm'], 'c' => 2],
                ['q' => 'Autorem "Lalki" jest:', 'a' => ['Henryk Sienkiewicz', 'Stefan Żeromski', 'Bolesław Prus', 'Eliza Orzeszkowa'], 'c' => 2],
                ['q' => 'Jak nazywa się polska noblistka z 1996 roku?', 'a' => ['Olga Tokarczuk', 'Wisława Szymborska', 'Maria Konopnicka', 'Zofia Nałkowska'], 'c' => 1],
                ['q' => 'Dramat "Wesele" napisał:', 'a' => ['Stanisław Wyspiański', 'Aleksander Fredro', 'Sławomir Mrożek', 'Witold Gombrowicz'], 'c' => 0],
                ['q' => 'Haiku to gatunek poezji pochodzący z:', 'a' => ['Chin', 'Indii', 'Japonii', 'Wietnamu'], 'c' => 2],
                ['q' => 'Cykl powieści o Harrym Potterze napisała:', 'a' => ['J.K. Rowling', 'Agatha Christie', 'Virginia Woolf', 'Jane Austen'], 'c' => 0],
            ],
            'Chemia' => [
                ['q' => 'Symbol chemiczny złota to:', 'a' => ['Ag', 'Au', 'Fe', 'Cu'], 'c' => 1],
                ['q' => 'Woda to tlenek:', 'a' => ['Węgla', 'Wodoru', 'Azotu', 'Siarki'], 'c' => 1],
                ['q' => 'PH równe 7 oznacza odczyn:', 'a' => ['Kwaśny', 'Zasadowy', 'Obojętny', 'Toksyczny'], 'c' => 2],
                ['q' => 'Najlżejszym pierwiastkiem jest:', 'a' => ['Hel', 'Tlen', 'Wodór', 'Lit'], 'c' => 2],
                ['q' => 'Elektrony mają ładunek:', 'a' => ['Dodatni', 'Ujemny', 'Obojętny', 'Zmienny'], 'c' => 1],
                ['q' => 'Tablicę Mendelejewa stworzył:', 'a' => ['Dmitrij Mendelejew', 'Maria Curie', 'Alfred Nobel', 'Louis Pasteur'], 'c' => 0],
                ['q' => 'Główny składnik powietrza to:', 'a' => ['Tlen', 'Azot', 'Dwutlenek węgla', 'Argon'], 'c' => 1],
                ['q' => 'Kwas solny ma wzór:', 'a' => ['H2SO4', 'HNO3', 'HCl', 'H2O'], 'c' => 2],
            ]
        ];

        return $db[$subject] ?? $db['Informatyka'];
    }
}
