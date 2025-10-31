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
                'avatar_url' => $inv->inviter->avatar_url, // Użyj akcesora
            ] : null,
        ];
    }

    /**
     * GET /api/me/dashboard
     *
     * Pobiera zagregowane dane dla pulpitu zalogowanego użytkownika.
     *
     * Parametry (filtry) z frontendu:
     * ?include=stats,myCourses,memberCourses,recentNotes,recentTests,invitations
     * (Domyślnie: wszystkie)
     *
     * ?limit=5
     * (Domyślnie: 5, Max: 20) - Liczba elementów w listach "recent"
     *
     * ?courses_q=nazwa&courses_sort=updated_at&courses_order=desc
     * (Filtry dla widżetów kursów)
     *
     * ?notes_q=tresc&notes_sort=title&notes_order=asc
     * (Filtry dla widżetu notatek)
     *
     * ?tests_q=temat&tests_sort=created_at&tests_order=desc
     * (Filtry dla widżetu testów)
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
        $defaultIncludes = 'stats,myCourses,memberCourses,recentNotes,recentTests,invitations';
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
            // 1. Pobierz kursy, upewnij się, że masz user_id (właściciela)
            $memberCourses = $user->courses()
                ->wherePivot('status', 'accepted')
                ->where('courses.user_id', '!=', $userId) // Tylko te, których nie jestem właścicielem
                ->when($coursesQuery, fn($q) => $q->where('courses.title', 'like', "%{$coursesQuery}%"))
                ->orderBy("courses.$coursesSortColumn", $coursesOrder) // Musimy wskazać tabelę
                ->limit($limit)
                // --- ZMIANA: Dodano 'courses.user_id' do pobrania właściciela ---
                ->select('courses.id', 'courses.title', 'courses.avatar', 'courses.type', 'courses.updated_at', 'courses_users.role', 'courses.user_id')
                ->get();

            // 2. Zbierz ID właścicieli i pobierz ich dane jednym zapytaniem
            $ownerIds = $memberCourses->pluck('user_id')->unique()->filter();
            $owners = collect();
            if ($ownerIds->isNotEmpty()) {
                $owners = User::whereIn('id', $ownerIds)
                    ->select('id', 'name', 'avatar')
                    ->get()
                    ->keyBy('id');
            }

            // 3. Zmapuj wyniki, dołączając ręcznie dane właściciela
            $response['data']['memberCourses'] = $memberCourses->map(function(Course $course) use ($owners) {
                $owner = $owners->get($course->user_id);

                // Użyj helpera do formatowania kursu
                $formattedCourse = $this->formatCourse($course, $course->pivot->role);

                // --- ZMIANA: Dołącz dane właściciela (z akcesorem avatar_url) ---
                $formattedCourse['owner'] = $owner ? [
                    'id' => $owner->id,
                    'name' => $owner->name,
                    'avatar_url' => $owner->avatar_url // Użyj akcesora
                ] : null;

                return $formattedCourse;
            });
        }

        // Widżet: 'recentNotes' (Moje ostatnie notatki)
        if (isset($includes['recentNotes'])) {
            $notesQuery = $request->query('notes_q');
            $notesSort = $request->query('notes_sort', 'updated_at');
            $notesOrder = $request->query('notes_order', 'desc');
            $notesSortColumn = in_array($notesSort, ['title', 'created_at', 'updated_at']) ? $notesSort : 'updated_at';

            $response['data']['recentNotes'] = Note::where('user_id', $userId)
                ->when($notesQuery, fn($q) => $q->where(function($sub) use ($notesQuery) {
                    $sub->where('title', 'like', "%{$notesQuery}%")
                        ->orWhere('description', 'like', "%{$notesQuery}%");
                }))
                ->withCount('files') // Dodaj liczbę plików
                // --- ZMIANA: Załaduj relację 'user' (autora) ---
                ->with('user:id,name,avatar')
                // --- ZMIANA: Dodaj 'user_id' do select, aby 'with' działało poprawnie ---
                ->select('id', 'title', 'is_private', 'updated_at', 'created_at', 'user_id')
                ->orderBy($notesSortColumn, $notesOrder)
                ->limit($limit)
                ->get()
                // --- ZMIANA: Zmapuj, aby dodać 'avatar_url' autora ---
                ->map(function (Note $note) {
                    // toArray() serializuje model ORAZ załadowane relacje
                    $data = $note->toArray();

                    // Ręcznie wywołaj akcesor avatar_url i dodaj go do serializowanej tablicy
                    if (isset($data['user']) && $note->user) {
                        $data['user']['avatar_url'] = $note->user->avatar_url;
                    }
                    return $data;
                });
        }

        // Widżet: 'recentTests' (Moje ostatnie testy)
        if (isset($includes['recentTests'])) {
            $testsQuery = $request->query('tests_q');
            $testsSort = $request->query('tests_sort', 'updated_at');
            $testsOrder = $request->query('tests_order', 'desc');
            $testsSortColumn = in_array($testsSort, ['title', 'created_at', 'updated_at']) ? $testsSort : 'updated_at';

            $response['data']['recentTests'] = Test::where('user_id', $userId)
                ->when($testsQuery, fn($q) => $q->where(function($sub) use ($testsQuery) {
                    $sub->where('title', 'like', "%{$testsQuery}%")
                        ->orWhere('description', 'like', "%{$testsQuery}%");
                }))
                ->withCount('questions') // Dodaj liczbę pytań
                // --- ZMIANA: Załaduj relację 'user' (autora) ---
                ->with('user:id,name,avatar')
                // --- ZMIANA: Dodaj 'user_id' do select, aby 'with' działało poprawnie ---
                ->select('id', 'title', 'status', 'updated_at', 'created_at', 'user_id')
                ->orderBy($testsSortColumn, $testsOrder)
                ->limit($limit)
                ->get()
                // --- ZMIANA: Zmapuj, aby dodać 'avatar_url' autora ---
                ->map(function (Test $test) {
                    // toArray() serializuje model ORAZ załadowane relacje
                    $data = $test->toArray();

                    // Ręcznie wywołaj akcesor avatar_url i dodaj go do serializowanej tablicy
                    if (isset($data['user']) && $test->user) {
                        $data['user']['avatar_url'] = $test->user->avatar_url;
                    }
                    return $data;
                });
        }

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
