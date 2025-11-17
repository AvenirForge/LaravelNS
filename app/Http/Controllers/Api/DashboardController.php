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
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as Http;

class DashboardController extends Controller
{
    /**
     * Helper do kanonizacji e-maila.
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
            'avatar_url' => $course->avatar_url, // Użyj akcesora modelu
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
                'avatar_url' => $inv->course->avatar_url,
            ] : null,
            'inviter' => $inv->inviter ? [
                'id' => $inv->inviter->id,
                'name' => $inv->inviter->name,
                'avatar_url' => $inv->inviter->avatar_url,
            ] : null,
        ];
    }

    /**
     * GET /api/me/dashboard
     *
     * Pobiera zagregowane dane dla pulpitu zalogowanego użytkownika.
     * Wyświetla treści (notatki/testy) użytkownika ORAZ treści z grup, do których należy,
     * w porządku chronologicznym, z informacją o pochodzeniu (kursie).
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

        // --- KROK KLUCZOWY: Identyfikacja dostępnych kursów ---
        // Pobieramy ID wszystkich kursów, do których user ma dostęp (jako właściciel lub członek),
        // aby móc wyświetlić pochodzące z nich notatki/testy.

        // 1. Kursy, których jest właścicielem
        $ownedCourseIds = Course::where('user_id', $userId)->pluck('id')->toArray();

        // 2. Kursy, w których jest aktywnym członkiem
        $memberCourseIds = DB::table('courses_users')
            ->where('user_id', $userId)
            ->whereIn('status', ['accepted', 'active', 'approved', 'joined'])
            ->pluck('course_id')
            ->toArray();

        // Scalona lista unikalnych ID kursów (Własne + Członkowskie)
        $allAllowedCourseIds = array_values(array_unique(array_merge($ownedCourseIds, $memberCourseIds)));


        // --- 2. Pobieranie danych dla widżetów ---

        // Widżet: 'stats' (Statystyki / Liczniki)
        if (isset($includes['stats'])) {
            $myCoursesCount = count($ownedCourseIds);
            $memberCoursesCount = count($memberCourseIds); // Kursy, gdzie jestem gościem

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

        // Wspólne filtry dla zapytań o kursy
        $coursesQuery = $request->query('courses_q');
        $coursesSort = $request->query('courses_sort', 'updated_at');
        $coursesOrder = $request->query('courses_order', 'desc');
        $coursesSortColumn = in_array($coursesSort, ['title', 'created_at', 'updated_at']) ? $coursesSort : 'updated_at';

        // Widżet: 'myCourses' (Moje kursy)
        if (isset($includes['myCourses'])) {
            $response['data']['myCourses'] = Course::where('user_id', $userId)
                ->when($coursesQuery, fn($q) => $q->where('title', 'like', "%{$coursesQuery}%"))
                ->orderBy($coursesSortColumn, $coursesOrder)
                ->limit($limit)
                ->select('id', 'title', 'avatar', 'type', 'updated_at')
                ->get()
                ->map(fn(Course $course) => $this->formatCourse($course));
        }

        // Widżet: 'memberCourses' (Kursy, w których uczestniczę)
        if (isset($includes['memberCourses'])) {
            $memberCourses = $user->courses()
                ->wherePivotIn('status', ['accepted', 'active', 'approved', 'joined'])
                ->where('courses.user_id', '!=', $userId) // Wyklucz własne
                ->when($coursesQuery, fn($q) => $q->where('courses.title', 'like', "%{$coursesQuery}%"))
                ->orderBy("courses.$coursesSortColumn", $coursesOrder)
                ->limit($limit)
                ->select('courses.id', 'courses.title', 'courses.avatar', 'courses.type', 'courses.updated_at', 'courses_users.role', 'courses.user_id')
                ->get();

            // Pobieranie danych właścicieli
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


        // --- WIDŻET: 'recentActivities' (Ostatnie aktywności - Moje + Grupowe) ---
        if (isset($includes['recentActivities'])) {
            $activitiesQuery = $request->query('activities_q');
            $activitiesSort = $request->query('activities_sort', 'updated_at');
            $activitiesOrder = $request->query('activities_order', 'desc');
            $activitiesType = $request->query('activities_type', 'all'); // 'all', 'note', 'test'
            $activitiesSortColumn = in_array($activitiesSort, ['title', 'created_at', 'updated_at']) ? $activitiesSort : 'updated_at';

            // 2. Budowanie zapytań (Notatki)
            // Pobieramy notatki, które są MOJE lub należą do KURSÓW, w których jestem
            $notesQuery = null;
            if (in_array($activitiesType, ['all', 'note'])) {
                $notesQuery = Note::query()
                    ->where(function (EloquentBuilder $q) use ($userId, $allAllowedCourseIds) {
                        $q->where('user_id', $userId) // Moje prywatne/publiczne
                        ->orWhereHas('courses', function ($cq) use ($allAllowedCourseIds) {
                            $cq->whereIn('courses.id', $allAllowedCourseIds); // Udostępnione w grupach
                        });
                    })
                    ->select(
                        'id',
                        DB::raw("'note' as type"),
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

            // 3. Budowanie zapytań (Testy)
            // Analogicznie: moje lub udostępnione w moich grupach
            $testsQuery = null;
            if (in_array($activitiesType, ['all', 'test'])) {
                $testsQuery = Test::query()
                    ->where(function (EloquentBuilder $q) use ($userId, $allAllowedCourseIds) {
                        $q->where('user_id', $userId)
                            ->orWhereHas('courses', function ($cq) use ($allAllowedCourseIds) {
                                $cq->whereIn('courses.id', $allAllowedCourseIds);
                            });
                    })
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

            // 4. Łączenie (UNION)
            $combinedQuery = null;
            if ($activitiesType === 'note') $combinedQuery = $notesQuery;
            elseif ($activitiesType === 'test') $combinedQuery = $testsQuery;
            else {
                if ($notesQuery && $testsQuery) $combinedQuery = $notesQuery->unionAll($testsQuery);
                elseif ($notesQuery) $combinedQuery = $notesQuery;
                else $combinedQuery = $testsQuery;
            }

            // 5. Pobranie "lekkiej" listy posortowanych aktywności
            $sortedActivities = collect();
            if ($combinedQuery) {
                $sortedActivities = DB::table($combinedQuery, 'activities')
                    ->distinct() // Zapobiega duplikatom jeśli element pasuje do wielu warunków
                    ->orderBy($activitiesSortColumn, $activitiesOrder)
                    ->limit($limit)
                    ->get();
            }

            // 6. Hydracja modeli z pełnymi danymi (w tym kursami)
            $noteIds = $sortedActivities->where('type', 'note')->pluck('id');
            $testIds = $sortedActivities->where('type', 'test')->pluck('id');

            // Pobieramy notatki z relacją 'courses' (pobieramy avatar, title, type, id)
            // Ważne: Pobieramy 'avatar', żeby akcesor avatar_url zadziałał
            $notes = Note::with([
                'user:id,name,avatar',
                'files',
                'courses:id,title,avatar,type'
            ])
                ->findMany($noteIds)
                ->keyBy('id');

            $tests = Test::with([
                'user:id,name,avatar',
                'courses:id,title,avatar,type'
            ])
                ->withCount('questions')
                ->findMany($testIds)
                ->keyBy('id');

            // 7. Mapowanie do finalnej struktury JSON
            $response['data']['recentActivities'] = $sortedActivities->map(function ($item) use ($notes, $tests) {
                $model = null;
                if ($item->type === 'note') $model = $notes->get($item->id);
                elseif ($item->type === 'test') $model = $tests->get($item->id);

                if (!$model) return null;

                $data = $model->toArray();
                $data['type'] = $item->type;

                // Formatowanie Autora
                if (isset($data['user']) && $model->user) {
                    $data['user']['avatar_url'] = $model->user->avatar_url;
                }

                // Formatowanie Kursów (Źródła zawartości)
                // Dostarczamy "pełen komplet danych" o kursach, z których pochodzi notatka
                if ($model->relationLoaded('courses')) {
                    $data['courses'] = $model->courses->map(function(Course $c) {
                        return [
                            'id' => $c->id,
                            'title' => $c->title,
                            'type' => $c->type,
                            'avatar_url' => $c->avatar_url, // URL do avatara kursu
                        ];
                    });
                } else {
                    $data['courses'] = [];
                }

                return $data;

            })->filter()->values();
        }


        // Widżet: 'invitations'
        if (isset($includes['invitations'])) {
            $meNorm = $this->canonicalEmail($user->email);
            $response['data']['invitations'] = Invitation::where('status', 'pending')
                ->where(function (EloquentBuilder $query) use ($meNorm, $userId) {
                    $query->whereRaw('LOWER(TRIM(invited_email)) = ?', [$meNorm])
                        ->orWhere('user_id', $userId);
                })
                ->with('course:id,title,avatar', 'inviter:id,name,avatar')
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(fn(Invitation $inv) => $this->formatInvitation($inv));
        }

        return response()->json($response, Http::HTTP_OK);
    }
}
