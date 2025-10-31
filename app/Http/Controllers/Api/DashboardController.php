<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Invitation;
use App\Models\Note;
use App\Models\Test;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB; // <--- WAŻNY IMPORT DLA UNION I DB::RAW
use Symfony\Component\HttpFoundation\Response as Http;

class DashboardController extends Controller
{
    /**
     * Helper do kanonizacji e-maila (skopiowany z InvitationController dla spójności).
     */
    private function canonicalEmail(string $email): string
    {
        $email = trim(mb_strtolower($email));
        if (!str_contains($email, '@')) return $email;
        [$local, $domain] = explode('@', $email, 2); $domainAscii = $domain;
        if (function_exists('idn_to_ascii')) { $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46); if ($ascii !== false) $domainAscii = $ascii; }
        if (in_array($domainAscii, ['gmail.com', 'googlemail.com'], true)) { $plusPos = strpos($local, '+'); if ($plusPos !== false) $local = substr($local, 0, $plusPos); $local = str_replace('.', '', $local); }
        return $local.'@'.$domainAscii;
    }

    /**
     * Helper do formatowania kursu (dodaje avatar_url i rolę).
     */
    private function formatCourse(Course $course, ?string $role = null): array
    {
        $data = [
            'id' => $course->id,
            'title' => $course->title,
            'avatar_url' => $course->avatar_url, // Użyj akcesora
            'type' => $course->type,
            'updated_at' => $course->updated_at?->toIso8601String(),
        ];
        if ($role) {
            $data['role'] = $role;
        }
        return $data;
    }

    /**
     * Helper do formatowania zaproszenia (dodaje avatary).
     */
    private function formatInvitation(Invitation $inv): array
    {
        return [
            'id' => $inv->id,
            'token' => $inv->token,
            'status' => $inv->status,
            'role' => $inv->role,
            'expires_at' => $inv->expires_at?->toIso8601String(),
            'created_at' => $inv->created_at?->toIso8601String(),
            'course' => $inv->course ? [
                'id' => $inv->course->id,
                'title' => $inv->course->title,
                'avatar_url' => $inv->course->avatar_url, // Użyj akcesora
            ] : null,
            'inviter' => $inv->inviter ? [
                'id' => $inv->inviter->id,
                'name' => $inv->inviter->name,
                'avatar_url' => $inviter->avatar_url, // Użyj akcesora
            ] : null,
        ];
    }

    /**
     * GET /api/me/dashboard
     *
     * Pobiera zagregowane dane dla pulpitu zalogowanego użytkownika.
     *
     * Parametry (filtry) z frontendu:
     * ?include=stats,myCourses,memberCourses,recentActivities,invitations
     * (Domyślnie: wszystkie)
     *
     * ?limit=5
     * (Domyślnie: 5, Max: 20) - Liczba elementów w listach "recent"
     *
     * ?courses_q=nazwa&courses_sort=updated_at&courses_order=desc
     * (Filtry dla widżetów kursów)
     *
     * --- ZMIANA: Wspólne filtry dla Aktywności ---
     * ?activities_q=tresc
     * ?activities_sort=updated_at
     * ?activities_order=desc
     * ?activities_type=all (all, note, test)
     * --- KONIEC ZMIANY ---
     */
    public function getDashboard(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = Auth::guard('api')->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);
        }
        $userId = $user->id;

        // --- 1. Przetwarzanie filtrów ---

        // Filtr `limit` (ile elementów na listę)
        $limit = max(1, min(20, (int) $request->query('limit', 5)));

        // Filtr `include` (które widżety dołączyć)
        // --- ZMIANA: Zastąpiono recentNotes/recentTests przez recentActivities ---
        $defaultIncludes = 'stats,myCourses,memberCourses,recentActivities,invitations';
        $includeParam = $request->query('include', $defaultIncludes);
        $includes = array_fill_keys(explode(',', $includeParam), true);

        // Przygotowanie obiektu odpowiedzi
        $response = [
            'meta' => [
                'requested_at' => now()->toIso8601String(),
                'included_widgets' => array_keys($includes),
                'limit_per_widget' => $limit,
            ],
            'data' => [],
        ];

        // --- 2. Pobieranie danych dla widżetów ---

        // Widżet: 'stats' (Statystyki / Liczniki)
        if (isset($includes['stats'])) {
            $myCoursesCount = Course::where('user_id', $userId)->count();
            // Liczy kursy, gdzie jestem członkiem (status 'accepted'), ale NIE jestem właścicielem
            $memberCoursesCount = $user->courses()
                ->wherePivot('status', 'accepted')
                ->where('courses.user_id', '!=', $userId)
                ->count();
            $notesCount = Note::where('user_id', $userId)->count();
            $testsCount = Test::where('user_id', $userId)->count();

            $meNorm = $this->canonicalEmail($user->email);
            $invitationsCount = Invitation::where('status', 'pending')
                ->where(function (EloquentBuilder $query) use ($meNorm, $userId) {
                    $query->whereRaw('LOWER(TRIM(invited_email)) = ?', [$meNorm])
                        ->orWhere('user_id', $userId);
                })
                ->count();

            $response['data']['stats'] = [
                'courses_owned' => $myCoursesCount,
                'courses_member' => $memberCoursesCount,
                'notes_total' => $notesCount,
                'tests_total' => $testsCount,
                'invitations_pending' => $invitationsCount,
            ];
        }

        // Wspólne filtry dla kursów
        $coursesQuery = $request->query('courses_q');
        $coursesSort = $request->query('courses_sort', 'updated_at');
        $coursesOrder = $request->query('courses_order', 'desc');
        $coursesSortColumn = in_array($coursesSort, ['title', 'created_at', 'updated_at']) ? $coursesSort : 'updated_at';

        // Widżet: 'myCourses' (Kursy, które posiadam)
        if (isset($includes['myCourses'])) {
            $response['data']['myCourses'] = Course::where('user_id', $userId)
                ->when($coursesQuery, fn($q) => $q->where('title', 'like', "%{$coursesQuery}%"))
                ->orderBy($coursesSortColumn, $coursesOrder)
                ->limit($limit)
                ->select('id', 'title', 'avatar', 'type', 'updated_at')
                ->get()
                ->map(fn(Course $course) => $this->formatCourse($course));
        }

        // Widżet: 'memberCourses' (Kursy, do których należę)
        if (isset($includes['memberCourses'])) {
            $memberCourses = $user->courses()
                ->wherePivot('status', 'accepted')
                ->where('courses.user_id', '!=', $userId)
                ->when($coursesQuery, fn($q) => $q->where('courses.title', 'like', "%{$coursesQuery}%"))
                ->orderBy("courses.$coursesSortColumn", $coursesOrder)
                ->limit($limit)
                ->select('courses.id', 'courses.title', 'courses.avatar', 'courses.type', 'courses.updated_at', 'courses_users.role', 'courses.user_id')
                ->get();

            $ownerIds = $memberCourses->pluck('user_id')->unique()->filter();
            $owners = collect();
            if ($ownerIds->isNotEmpty()) {
                $owners = User::whereIn('id', $ownerIds)
                    ->select('id', 'name', 'avatar')
                    ->get()
                    ->keyBy('id');
            }

            $response['data']['memberCourses'] = $memberCourses->map(function(Course $course) use ($owners) {
                $owner = $owners->get($course->user_id);
                $formattedCourse = $this->formatCourse($course, $course->pivot->role);
                $formattedCourse['owner'] = $owner ? [
                    'id' => $owner->id,
                    'name' => $owner->name,
                    'avatar_url' => $owner->avatar_url
                ] : null;
                return $formattedCourse;
            });
        }


        // --- ZMIANA: Scalony widżet 'recentActivities' ---
        if (isset($includes['recentActivities'])) {
            // 1. Pobierz filtry dla aktywności
            $activitiesQuery = $request->query('activities_q');
            $activitiesSort = $request->query('activities_sort', 'updated_at');
            $activitiesOrder = $request->query('activities_order', 'desc');
            $activitiesType = $request->query('activities_type', 'all'); // 'all', 'note', 'test'
            $activitiesSortColumn = in_array($activitiesSort, ['title', 'created_at', 'updated_at']) ? $activitiesSort : 'updated_at';

            // 2. Zbuduj bazowe zapytania (tylko te, które są potrzebne)
            $notesQuery = null;
            if (in_array($activitiesType, ['all', 'note'])) {
                $notesQuery = Note::where('user_id', $userId)
                    // Wybierz kolumny potrzebne do UNION i filtrowania
                    ->select(
                        'id',
                        DB::raw("'note' as type"), // Ręcznie dodaj typ
                        'title',
                        'description', // Potrzebne do 'activities_q'
                        'updated_at',
                        'created_at',
                        'user_id' // Potrzebne do późniejszej hydracji
                    )
                    // Zastosuj filtr 'q' (przed UNION dla wydajności)
                    ->when($activitiesQuery, function($q) use ($activitiesQuery) {
                        $q->where(function($sub) use ($activitiesQuery) {
                            $sub->where('title', 'like', "%{$activitiesQuery}%")
                                ->orWhere('description', 'like', "%{$activitiesQuery}%");
                        });
                    });
            }

            $testsQuery = null;
            if (in_array($activitiesType, ['all', 'test'])) {
                $testsQuery = Test::where('user_id', $userId)
                    ->select(
                        'id',
                        DB::raw("'test' as type"),
                        'title',
                        'description',
                        'updated_at',
                        'created_at',
                        'user_id'
                    )
                    ->when($activitiesQuery, function($q) use ($activitiesQuery) {
                        $q->where(function($sub) use ($activitiesQuery) {
                            $sub->where('title', 'like', "%{$activitiesQuery}%")
                                ->orWhere('description', 'like', "%{$activitiesQuery}%");
                        });
                    });
            }

            // 3. Połącz zapytania (UNION)
            if ($activitiesType === 'note') {
                $combinedQuery = $notesQuery;
            } elseif ($activitiesType === 'test') {
                $combinedQuery = $testsQuery;
            } else {
                // Jeśli $notesQuery lub $testsQuery jest null (co nie powinno się zdarzyć przy 'all', ale dla bezpieczeństwa)
                if ($notesQuery) {
                    $combinedQuery = $notesQuery->unionAll($testsQuery);
                } else {
                    $combinedQuery = $testsQuery;
                }
            }

            // 4. Wykonaj posortowane i ograniczone zapytanie UNION
            // Musimy to zrobić przez DB::table(), aby posortować WYNIK unii
            $sortedActivities = DB::table($combinedQuery, 'activities')
                ->orderBy($activitiesSortColumn, $activitiesOrder)
                ->limit($limit)
                ->get(); // Zwraca kolekcję stdClass

            // 5. Re-Hydracja: Pobierz pełne modele Eloquent dla wyników
            $noteIds = $sortedActivities->where('type', 'note')->pluck('id');
            $testIds = $sortedActivities->where('type', 'test')->pluck('id');

            // Pobierz modele notatek wraz z *wszystkimi* wymaganymi relacjami
            $notes = Note::with([
                'user:id,name,avatar', // Autor
                'files',               // Pliki notatki
                'courses:id'           // Kursy (tylko ID dla normalizacji)
            ])
                ->findMany($noteIds)
                ->keyBy('id'); // Kluczem jest ID notatki

            // Pobierz modele testów wraz z *wszystkimi* wymaganymi relacjami
            $tests = Test::with([
                'user:id,name,avatar', // Autor
                'courses:id',          // Kursy (tylko ID)
            ])
                ->withCount('questions') // Licznik pytań
                ->findMany($testIds)
                ->keyBy('id'); // Kluczem jest ID testu

            // 6. Zbuduj ostateczną, posortowaną tablicę odpowiedzi
            $response['data']['recentActivities'] = $sortedActivities->map(function ($item) use ($notes, $tests) {
                $model = null;
                if ($item->type === 'note') {
                    $model = $notes->get($item->id);
                } elseif ($item->type === 'test') {
                    $model = $tests->get($item->id);
                }

                if (!$model) {
                    return null; // Model mógł zostać usunięty między zapytaniami
                }

                // Przekształć model Eloquent (z relacjami) w tablicę
                $data = $model->toArray();
                // Dodaj pole 'type'
                $data['type'] = $item->type;

                // Dodaj 'avatar_url' do autora (jeśli istnieje)
                if (isset($data['user']) && $model->user) {
                    $data['user']['avatar_url'] = $model->user->avatar_url;
                }

                // Znormalizuj relacje kursów do tablicy ID
                if (isset($data['courses'])) {
                    $data['course_ids'] = $model->courses->pluck('id');
                    unset($data['courses']); // Usuń pełne obiekty, aby nie powielać danych
                } else {
                    $data['course_ids'] = [];
                }

                return $data;

            })->filter(); // Usuń ewentualne nulle
        }
        // --- KONIEC ZMIANY: 'recentActivities' ---


        // Widżet: 'invitations' (Oczekujące zaproszenia)
        if (isset($includes['invitations'])) {
            $meNorm = $this->canonicalEmail($user->email);
            $response['data']['invitations'] = Invitation::where('status', 'pending')
                ->where(function (EloquentBuilder $query) use ($meNorm, $userId) {
                    $query->whereRaw('LOWER(TRIM(invited_email)) = ?', [$meNorm])
                        ->orWhere('user_id', $userId);
                })
                ->with('course:id,title,avatar', 'inviter:id,name,avatar') // Pobierz relacje
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(fn(Invitation $inv) => $this->formatInvitation($inv));
        }

        return response()->json($response, Http::HTTP_OK);
    }
}
