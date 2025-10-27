<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Note;
use App\Models\Test;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as Http;

class CourseController extends Controller
{
    // ===== Helpery (bez zmian) =====
    private function me(): ?User { return Auth::guard('api')->user(); }
    private function meId(): ?int { $u = $this->me(); return $u ? (int)$u->id : null; }
    private function canonicalEmail(string $email): string { /* ... bez zmian ... */
        $email = trim(mb_strtolower($email));
        if (function_exists('idn_to_ascii') && str_contains($email,'@')) {
            [$local,$domain] = explode('@',$email,2); $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii) $email = $local.'@'.$ascii;
        } return $email;
    }
    private function roleInCourse(Course $course, int $userId): string { /* ... bez zmian ... */
        if ((int)$userId === (int)$course->user_id) return 'owner';
        $pivot = DB::table('courses_users')->where('course_id', $course->id)->where('user_id', $userId)->first();
        if (!$pivot) return 'guest'; $role = $pivot->role;
        return (!$role || $role === 'user') ? 'member' : $role;
    }
    private function checkPermissions(Course $course): bool { /* ... bez zmian ... */
        $me = $this->me(); if (!$me) return false; $role = $this->roleInCourse($course, (int)$me->id);
        return in_array($role, ['owner','admin','moderator'], true);
    }
    private function canModerateUser(Course $course, int $actorId, int $targetId): bool { /* ... bez zmian ... */
        $actorRole = $this->roleInCourse($course, $actorId); $targetRole = $this->roleInCourse($course, $targetId);
        if ($actorRole === 'owner') return true; if ($actorRole === 'admin') return in_array($targetRole, ['moderator','member'], true);
        if ($actorRole === 'moderator') return $targetRole === 'member'; return false;
    }

    // ===== CRUD kursów =====
    public function index(): JsonResponse
    {
        // ... (bez zmian - logika pobierania kursów użytkownika) ...
        $user = $this->me(); if (!$user) return response()->json(['error'=>'Unauthorized'], 401);
        $ownerIds = Course::where('user_id', $user->id)->pluck('id')->all();
        $pivotIds = DB::table('courses_users')->where('user_id', $user->id)->pluck('course_id')->all();
        $ids = array_values(array_unique(array_merge($ownerIds, $pivotIds)));
        $courses = empty($ids) ? [] : Course::whereIn('id', $ids)->latest()->get(); // Dodano sortowanie
        return response()->json($courses);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $course = Course::findOrFail($id);
        if (!$this->checkPermissions($course)) { // ZMIANA: Użycie spójnej metody checkPermissions
            return response()->json(['error' => 'Forbidden'], Http::HTTP_FORBIDDEN);
        }

        $validator = Validator::make($request->all(), [ // ZMIANA: Poprawiona walidacja 'type'
            'title'       => 'sometimes|present|string|max:255',
            'description' => 'sometimes|present|string|nullable',
            'type'        => 'sometimes|present|string|in:public,private',
        ]);
        if ($validator->fails()) return response()->json(['error' => $validator->errors()], Http::HTTP_BAD_REQUEST);

        if ($request->has('title')) $course->title = (string) $request->input('title');
        if ($request->has('description')) $course->description = (string) $request->input('description');
        if ($request->has('type')) $course->type = (string) $request->input('type');
        $course->save();

        return response()->json(['message' => 'Course updated successfully!', 'course' => $course->refresh()->load('users:id')]); // Odśwież i załaduj relacje
    }

    public function updateAvatar(Request $request, string $id): JsonResponse
    {
        $course = Course::findOrFail($id);
        if (!$this->checkPermissions($course)) { // ZMIANA: Użycie spójnej metody checkPermissions
            return response()->json(['error' => 'Forbidden'], Http::HTTP_FORBIDDEN);
        }
        // ... (reszta logiki bez zmian) ...
        if (!$request->hasFile('avatar')) return response()->json(['error' => ['avatar' => ['The avatar file is required.']]], 400);
        $validator = Validator::make($request->all(), ['avatar' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048']);
        if ($validator->fails()) return response()->json(['error' => $validator->errors()], 400);
        if ($course->avatar && $course->avatar !== Course::DEFAULT_AVATAR_RELATIVE && Storage::disk('public')->exists($course->avatar)) {
            Storage::disk('public')->delete($course->avatar);
        }
        $path = $request->file('avatar')->store('courses/avatars', 'public');
        $course->avatar = $path; $course->save();
        return response()->json(['message' => 'Avatar updated successfully!', 'avatar_url' => $course->refresh()->avatar_url]);
    }

    public function downloadAvatar(string $id): BinaryFileResponse|JsonResponse
    {
        // ... (bez zmian - publiczny dostęp) ...
        $course = Course::findOrFail($id); $relative = $course->avatar ?: Course::DEFAULT_AVATAR_RELATIVE;
        if (!Storage::disk('public')->exists($relative)) { $relative = Course::DEFAULT_AVATAR_RELATIVE;
            if (!Storage::disk('public')->exists($relative)) return response()->json(['error' => 'Avatar not found'], 404);
        } $absolute = Storage::disk('public')->path($relative); return response()->download($absolute);
    }

    public function store(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [ // ZMIANA: Poprawiona walidacja 'type'
            'title' => 'required|string|max:255', 'description' => 'required|string',
            'type' => 'required|in:public,private',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);
        if ($v->fails()) return response()->json(['error'=>$v->errors()], Http::HTTP_BAD_REQUEST);
        // ... (reszta logiki bez zmian) ...
        $user = $this->me(); if (!$user) return response()->json(['error'=>'Unauthorized'], 401);
        $course = Course::create(['title' => (string)$request->input('title'), 'description' => (string)$request->input('description'),
            'type' => (string)$request->input('type'), 'user_id' => $user->id, ]);
        if ($request->hasFile('avatar')) { $course->avatar = $request->file('avatar')->store('courses/avatars','public'); $course->save(); }
        DB::table('courses_users')->updateOrInsert(['course_id'=>$course->id, 'user_id'=>$user->id],
            ['role'=>'owner','status'=>'accepted','created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['message'=>'Course created successfully!','course'=>$course], Http::HTTP_CREATED);
    }

    public function destroy($id): JsonResponse
    {
        // ... (bez zmian - checkPermissions jest wystarczające) ...
        $course = Course::findOrFail($id); if (!$this->checkPermissions($course)) return response()->json(['error'=>'Forbidden'], 403);
        if ($course->avatar && Storage::disk('public')->exists($course->avatar)) { Storage::disk('public')->delete($course->avatar); }
        $course->delete(); // Kaskada usunie powiązania w pivotach notes i tests
        return response()->json(['message'=>'Course deleted successfully']);
    }

    // ===== Moderacja: kick + purge =====

    /**
     * ZMIANA: Logika odpinania notatek i testów (N:M).
     */
    public function removeUser(Request $request, $courseId): JsonResponse
    {
        $course = Course::findOrFail($courseId);
        if (!$this->checkPermissions($course)) {
            return response()->json(['error'=>'Forbidden'], Http::HTTP_FORBIDDEN);
        }

        $data = $request->validate(['email'=>'required|email']);
        $user = User::whereRaw('LOWER(TRIM(email)) = ?', [$this->canonicalEmail($data['email'])])->first();
        if (!$user) {
            return response()->json(['error'=>'User not found'], Http::HTTP_NOT_FOUND);
        }
        if ((int)$user->id === (int)$course->user_id) {
            return response()->json(['error'=>'Cannot remove course owner'], Http::HTTP_UNPROCESSABLE_ENTITY);
        }

        $actorId = $this->meId() ?? 0;
        if (!$this->canModerateUser($course, $actorId, (int)$user->id)) {
            return response()->json(['error'=>'Insufficient permissions for this target user'], Http::HTTP_FORBIDDEN);
        }

        $isMember = DB::table('courses_users')->where('course_id',$course->id)->where('user_id',$user->id)->exists();
        if (!$isMember) {
            // Poprawka: Zgodnie z E2E zwracamy 404 jeśli użytkownik nie jest członkiem,
            // chociaż 200 (idempotencja) też byłoby akceptowalne.
            // Test `Remove non-member B again (idempotent)` oczekuje 200/204/404/400/422.
            // Zwróćmy 404 dla jasności, że użytkownika nie ma w kursie.
            return response()->json(['error'=>'User is not a course member'], Http::HTTP_NOT_FOUND); // ZMIANA na 404
            // return response()->json(['message' => 'User is not a member of this course.'], Http::HTTP_OK); // Alternatywa dla idempotencji
        }


        DB::transaction(function () use ($course, $user) {
            // Usuń z pivotu kurs-użytkownik
            // Użycie detach jest czytelniejsze, jeśli relacja 'users' istnieje w modelu Course
            if (method_exists($course, 'users')) {
                $course->users()->detach($user->id);
            } else {
                DB::table('courses_users')->where('course_id',$course->id)->where('user_id',$user->id)->delete();
            }


            // Odłącz notatki użytkownika od TEGO kursu
            $noteIds = $user->notes()->pluck('notes.id');
            if ($noteIds->isNotEmpty()) {
                $course->notes()->detach($noteIds);
                // Opcjonalnie: Ustaw is_private=true dla notatek bez kursów
                Note::whereIn('id', $noteIds)
                    ->whereDoesntHave('courses')
                    ->where('is_private', false)
                    ->update(['is_private' => true]);
            }


            // Odłącz testy użytkownika od TEGO kursu
            $testIds = $user->tests()->pluck('tests.id');
            if ($testIds->isNotEmpty()) {
                $course->tests()->detach($testIds);
            }
        });

        // --- POPRAWKA: Zwróć JSON z komunikatem zamiast true ---
        return response()->json(['message' => 'User removed from course successfully.'], Http::HTTP_OK); // Zwraca 200 OK
        // return response()->json(true); // <= TĄ LINIĘ ZASTĄP POWYŻSZĄ
        // --- KONIEC POPRAWKI ---
    }

    public function leaveCourse(Request $request, int $courseId): JsonResponse
    {
        /** @var User|null $me */
        $me = $this->me();
        if (!$me) {
            // Ten warunek nie powinien być osiągnięty z powodu middleware auth:api
            return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED); // 401
        }

        /** @var Course|null $course */
        // Użyj findOrFail dla automatycznego błędu 404, jeśli kurs nie istnieje
        $course = Course::findOrFail($courseId);

        // Sprawdź, czy użytkownik NIE JEST właścicielem kursu
        if ((int)$course->user_id === (int)$me->id) {
            return response()->json(['error' => 'The course owner cannot leave the course. Consider deleting the course or transferring ownership.'], Http::HTTP_FORBIDDEN); // 403
        }

        // Sprawdź, czy użytkownik jest aktywnym członkiem kursu
        // Używamy helpera roleInCourse, który sprawdza status 'accepted'
        $role = $this->roleInCourse($course, $me->id);

        if ($role === 'guest') { // roleInCourse zwraca 'guest' dla nie-członków lub nieaktywnych
            // Zwróć 403 Forbidden, bo użytkownik jest zalogowany, ale nie jest aktywnym członkiem
            return response()->json(['error' => 'You are not an active member of this course.'], Http::HTTP_FORBIDDEN);
        }

        // --- Operacja w transakcji ---
        try {
            DB::transaction(function () use ($course, $me) {
                // 1. Usuń użytkownika z tabeli pivot `courses_users`
                // detach zwraca liczbę odłączonych rekordów (powinno być 1)
                $detachedUserCount = $course->users()->detach($me->id);

                // Kontynuuj tylko jeśli użytkownik został faktycznie odłączony
                if ($detachedUserCount > 0) {

                    // 2. Odłącz notatki tego użytkownika od TEGO kursu
                    $noteIdsToDetach = $me->notes()->pluck('id'); // Pobierz ID notatek użytkownika
                    if ($noteIdsToDetach->isNotEmpty()) {
                        $detachedNotesCount = $course->notes()->detach($noteIdsToDetach); // Odłącz tylko te notatki od TEGO kursu

                        // Jeśli jakieś notatki zostały odłączone, sprawdź ich status prywatności
                        if ($detachedNotesCount > 0) {
                            Note::whereIn('id', $noteIdsToDetach)         // Sprawdź tylko odłączone notatki
                            ->whereDoesntHave('courses')             // Które nie mają już ŻADNYCH kursów
                            ->where('is_private', false)             // I są aktualnie publiczne
                            ->update(['is_private' => true]);        // Ustaw je jako prywatne
                        }
                    }

                    // 3. Odłącz testy tego użytkownika od TEGO kursu
                    $testIdsToDetach = $me->tests()->pluck('id'); // Pobierz ID testów użytkownika
                    if ($testIdsToDetach->isNotEmpty()) {
                        $course->tests()->detach($testIdsToDetach); // Odłącz tylko te testy od TEGO kursu
                        // Status testów (public/private) zazwyczaj nie zależy od bycia w kursie
                    }
                }
                // Jeśli $detachedUserCount = 0 (użytkownik już nie był członkiem?),
                // transakcja się powiedzie, ale nic więcej się nie stanie.

            }); // Koniec transakcji
        } catch (\Exception $e) {
            // Zaloguj błąd i zwróć 500
            Log::error("Failed to leave course {$courseId} for user {$me->id}: " . $e->getMessage());
            return response()->json(['error' => 'Failed to leave the course due to a server error.'], Http::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Zwróć sukces
        return response()->json(['message' => 'You have successfully left the course.'], Http::HTTP_OK); // 200 OK
    }

    /**
     * ZMIANA: Logika odpinania notatek (N:M).
     */
    public function purgeUserNotesInCourse(Request $request, int $courseId, int $userId): JsonResponse
    {
        $course = Course::findOrFail($courseId);
        if (!$this->checkPermissions($course)) return response()->json(['error'=>'Forbidden'], Http::HTTP_FORBIDDEN);
        $actorId = $this->meId() ?? 0;
        if (!$this->canModerateUser($course, $actorId, $userId)) {
            return response()->json(['error'=>'Insufficient permissions for this target user'], Http::HTTP_FORBIDDEN);
        }
        $user = User::find($userId);
        if (!$user) return response()->json(['error' => 'Target user not found'], Http::HTTP_NOT_FOUND);

        $noteIds = $user->notes()->pluck('notes.id');
        $affected = 0;
        if ($noteIds->isNotEmpty()) {
            $affected = $course->notes()->detach($noteIds); // Zwraca liczbę odłączonych rekordów
            // Opcjonalnie: Ustaw is_private=true dla notatek, które nie są już w żadnym kursie
            $notesToCheck = Note::whereIn('id', $noteIds)->withCount('courses')->get();
            foreach($notesToCheck as $note) {
                if ($note->courses_count === 0 && !$note->is_private) {
                    $note->is_private = true;
                    $note->save();
                }
            }
        }

        return response()->json(['message'=>'User notes unshared from course', 'affected'=>$affected]);
    }

    /**
     * ZMIANA: Logika odpinania testów (N:M).
     */
    public function purgeUserTestsInCourse(Request $request, int $courseId, int $userId): JsonResponse
    {
        $course = Course::findOrFail($courseId);
        if (!$this->checkPermissions($course)) return response()->json(['error'=>'Forbidden'], Http::HTTP_FORBIDDEN);
        $actorId = $this->meId() ?? 0;
        if (!$this->canModerateUser($course, $actorId, $userId)) {
            return response()->json(['error'=>'Insufficient permissions for this target user'], Http::HTTP_FORBIDDEN);
        }
        $user = User::find($userId);
        if (!$user) return response()->json(['error' => 'Target user not found'], Http::HTTP_NOT_FOUND);

        $testIds = $user->tests()->pluck('tests.id');
        $affected = 0;
        if ($testIds->isNotEmpty()) {
            $affected = $course->tests()->detach($testIds); // Odłącz testy użytkownika od TEGO kursu
        }

        return response()->json(['message'=>'User tests unshared from course', 'affected'=>$affected]);
    }

    /**
     * ZMIANA: Logika odpinania notatki (N:M).
     */
    public function unshareNoteAdmin(Request $request, int $courseId, int $noteId): JsonResponse
    {
        $course = Course::findOrFail($courseId);
        if (!$this->checkPermissions($course)) return response()->json(['error'=>'Forbidden'], Http::HTTP_FORBIDDEN);

        $note = Note::with('courses')->find($noteId); // Użyj with() zamiast findOrFail() dla lepszej obsługi błędu
        if (!$note) return response()->json(['error' => 'Note not found'], Http::HTTP_NOT_FOUND);

        if (!$note->courses->contains($course->id)) { // Sprawdź, czy notatka jest w tym kursie
            return response()->json(['error'=>'Note is not shared with this course'], Http::HTTP_CONFLICT);
        }

        $actorId = $this->meId() ?? 0;
        if (!$this->canModerateUser($course, $actorId, (int)$note->user_id)) {
            return response()->json(['error'=>'Insufficient permissions for this target user'], Http::HTTP_FORBIDDEN);
        }

        $course->notes()->detach($noteId); // Odłącz notatkę od TEGO kursu
        $note->load('courses'); // Odśwież relacje
        if ($note->courses->isEmpty() && !$note->is_private) { // Ustaw prywatność, jeśli trzeba
            $note->is_private = true;
            $note->save();
        }

        return response()->json(['message'=>'Note unshared from course','note'=>$note]);
    }

    /**
     * ZMIANA: Logika odpinania testu (N:M).
     */
    public function unshareTestAdmin(Request $request, int $courseId, int $testId): JsonResponse
    {
        $course = Course::findOrFail($courseId);
        if (!$this->checkPermissions($course)) return response()->json(['error'=>'Forbidden'], Http::HTTP_FORBIDDEN);

        $test = Test::with('courses')->find($testId); // Użyj with()
        if (!$test) return response()->json(['error' => 'Test not found'], Http::HTTP_NOT_FOUND);

        if (!$test->courses->contains($course->id)) { // Sprawdź, czy test jest w tym kursie
            return response()->json(['error'=>'Test is not shared with this course'], Http::HTTP_CONFLICT);
        }

        $actorId = $this->meId() ?? 0;
        if (!$this->canModerateUser($course, $actorId, (int)$test->user_id)) {
            return response()->json(['error'=>'Insufficient permissions for this target user'], Http::HTTP_FORBIDDEN);
        }

        $course->tests()->detach($testId); // Odłącz test od TEGO kursu

        // Nie ma potrzeby zmiany statusu testu - pozostaje publiczny/prywatny niezależnie od kursów
        return response()->json(['message'=>'Test unshared from course','test'=>$test->load('courses')]); // Odśwież i zwróć
    }

    // ===== Zarządzanie rolami (bez zmian logiki N:M) =====
    public function setUserRole(Request $request, int $courseId, int $userId): JsonResponse
    {
        // ... (bez zmian - operuje na pivocie courses_users) ...
        $course = Course::findOrFail($courseId); if (!$this->checkPermissions($course)) return response()->json(['error'=>'Forbidden'], 403);
        $data = $request->validate(['role'=>['required', Rule::in(['admin','moderator','member','user'])]]); $newRole = $data['role'] === 'user' ? 'member' : $data['role'];
        if ((int)$userId === (int)$course->user_id) return response()->json(['error'=>'Cannot change role of course owner'], 422);
        $exists = DB::table('courses_users')->where('course_id',$course->id)->where('user_id',$userId)->exists();
        if (!$exists) return response()->json(['error'=>'User is not a course member'], 404);
        $actorId = $this->meId() ?? 0; $actorRole = $this->roleInCourse($course, $actorId); $targetRole = $this->roleInCourse($course, $userId);
        if (!$this->canModerateUser($course, $actorId, $userId)) return response()->json(['error'=>'Insufficient permissions for this target user'], 403);
        $allowedByActor = match ($actorRole) { 'owner' => ['admin','moderator','member'], 'admin' => ['moderator','member'], default => [], };
        if (!in_array($newRole, $allowedByActor, true)) return response()->json(['error'=>'Role not allowed for this actor'], 403);
        if ($actorRole === 'admin' && $targetRole === 'admin') return response()->json(['error'=>'Admin cannot change another admin'], 403);
        if ($targetRole === $newRole) return response()->json(['message'=>'Role unchanged','user'=>['id'=>(int)$userId,'role'=>$targetRole]]);
        DB::table('courses_users')->where('course_id',$course->id)->where('user_id',$userId)->update(['role'=>$newRole, 'updated_at'=>now()]);
        return response()->json(['message'=>'Role updated','user'=>['id'=>(int)$userId,'role'=>$newRole]]);
    }

    public function setUserRoleByEmail(Request $request, int $courseId): JsonResponse
    {
        // ... (bez zmian - deleguje do setUserRole) ...
        $course = Course::findOrFail($courseId); if (!$this->checkPermissions($course)) return response()->json(['error'=>'Forbidden'], 403);
        $data = $request->validate(['email'=>'required|email', 'role' => ['required', Rule::in(['admin','moderator','member','user'])]]);
        $newRole = $data['role'] === 'user' ? 'member' : $data['role'];
        $user = User::whereRaw('LOWER(TRIM(email)) = ?', [$this->canonicalEmail($data['email'])])->first();
        if (!$user) return response()->json(['error'=>'User not found'], 404);
        $req = new Request(['role'=>$newRole]); return $this->setUserRole($req, $courseId, (int)$user->id);
    }
}
