<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Test;
use App\Models\TestsQuestion;
use App\Models\TestsAnswer;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as Http;

class TestController extends Controller
{
    // =================== PRIVATE TESTS (ME) ===================
    // Metody: indexForUser, storeForUser, showForUser, updateForUser, destroyForUser
    //         questionsForUser, storeQuestion, updateQuestion, destroyQuestion
    //         storeAnswer, getAnswersForQuestion, updateAnswer, destroyAnswer
    // POZOSTAJĄ BEZ ZMIAN - operują na testach prywatnych użytkownika (course_id=null).
    // Kod bez zmian - pominięty dla zwięzłości (zakładamy, że jest poprawny)
    public function indexForUser()
    {
        $user = Auth::user();
        if (!$user) return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);
        $tests = Test::where('user_id', $user->id)
            ->withCount('questions')
            ->latest()
            ->get();
        return response()->json($tests);
    }

    public function storeForUser(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);

        $validated = $request->validate([
            'title'       => 'required|string|max:255|unique:tests,title,NULL,id,user_id,'.$user->id,
            'description' => 'nullable|string|max:1000',
            'status'      => 'required|in:private,public',
        ]);

        $test = Test::create([
            'user_id'     => $user->id,
            'title'       => $validated['title'],
            'description' => $validated['description'] ?? null,
            'status'      => $validated['status'] ?? Test::STATUS_PRIVATE,
        ]);

        // --- POPRAWKA: Zwracamy zagnieżdżony obiekt dla spójności ---
        return response()->json(['test' => $test], Http::HTTP_CREATED);
    }

    public function showForUser($id)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);

        $test = Test::where('id', $id)
            ->where('user_id', $user->id)
            ->with('questions.answers', 'courses:id,title') // Dodano ładowanie kursów
            ->firstOrFail(); // Użyj firstOrFail dla automatycznego 404

        // --- POPRAWKA: Zwracamy zagnieżdżony obiekt dla spójności ---
        return response()->json(['test' => $test]);
    }


    public function updateForUser(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);

        $test = Test::where('id', $id)->where('user_id', $user->id)->firstOrFail();

        $validated = $request->validate([
            'title'       => 'required|string|max:255|unique:tests,title,'.$test->id.',id,user_id,'.$user->id,
            'description' => 'nullable|string|max:1000',
            'status'      => 'required|in:private,public,archived',
        ]);

        // Jeśli zmieniamy status na prywatny, odłącz od wszystkich kursów
        if ($validated['status'] === Test::STATUS_PRIVATE && $test->status !== Test::STATUS_PRIVATE) {
            $test->courses()->detach();
        }

        $test->update($validated);
        $test->load('courses:id,title'); // Załaduj kursy po aktualizacji

        // --- POPRAWKA: Zwracamy zagnieżdżony obiekt dla spójności ---
        return response()->json(['test' => $test]);
    }

    public function destroyForUser($id)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);

        $test = Test::where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        // Nie trzeba ręcznie detach - relacja pivot ma onDelete('cascade')
        $test->delete();

        return response()->json(['message' => 'Test deleted successfully.'], Http::HTTP_OK); // Użyj 200 OK zamiast 204 No Content, bo zwracamy wiadomość
    }

    // Metody dla pytań i odpowiedzi (questionsForUser, storeQuestion, updateQuestion, etc.)
    // Zakładamy, że są poprawne i autoryzacja działa (sprawdzanie właściciela testu)
    // ... (kod bez zmian) ...
    public function questionsForUser($testId)
    {
        $user = Auth::user();
        $test = Test::where('id', $testId)->where('user_id', $user->id)->firstOrFail();

        // Użycie relacji Eloquent jest czytelniejsze
        $questions = $test->questions()->with('answers')->orderBy('id')->get()
            ->map(function ($q) {
                // Mapowanie odpowiedzi wewnątrz mapowania pytań
                $answers = $q->answers->map(fn($a) => [
                    'id' => $a->id, 'question_id' => $a->question_id, 'answer' => $a->answer,
                    'is_correct' => (bool)$a->is_correct,
                    'created_at' => $a->created_at?->toISOString(), 'updated_at' => $a->updated_at?->toISOString(), // Użyj toISOString()
                ])->all();

                return [
                    'id' => $q->id, 'test_id' => $q->test_id, 'question' => $q->question,
                    'created_at' => $q->created_at?->toISOString(), 'updated_at' => $q->updated_at?->toISOString(),
                    'answers' => $answers,
                ];
            })->all();

        return response()->json(['questions' => $questions]);
    }

    public function storeQuestion(Request $request, $testId): JsonResponse
    {
        $user = Auth::user();
        $test = Test::where('id', $testId)->where('user_id', $user->id)->firstOrFail(); // Autoryzacja właściciela

        if ($test->questions()->count() >= 20) {
            return response()->json(['message' => 'Cannot add more than 20 questions'], Http::HTTP_BAD_REQUEST);
        }

        $validated = $request->validate(['question' => 'required|string|max:1000']);
        $question = $test->questions()->create($validated);
        // --- POPRAWKA: Zwracamy zagnieżdżony obiekt ---
        return response()->json(['question' => $question], Http::HTTP_CREATED);
    }

    public function updateQuestion(Request $request, $testId, $questionId): JsonResponse
    {
        $user = Auth::user();
        $test = Test::where('id', $testId)->where('user_id', $user->id)->firstOrFail();
        $question = $test->questions()->findOrFail($questionId);

        $validated = $request->validate(['question' => 'required|string|max:1000']);
        $question->update($validated);
        // --- POPRAWKA: Zwracamy zagnieżdżony obiekt ---
        return response()->json(['question' => $question]);
    }

    public function destroyQuestion($testId, $questionId): JsonResponse
    {
        $user = Auth::user();
        $test = Test::where('id', $testId)->where('user_id', $user->id)->firstOrFail();
        $question = $test->questions()->findOrFail($questionId);
        $question->delete();
        return response()->json(['message' => 'Question deleted successfully.'], Http::HTTP_OK);
    }

    public function storeAnswer(Request $request, $testId, $questionId): JsonResponse
    {
        $user = Auth::user();
        $test = Test::where('id', $testId)->where('user_id', $user->id)->firstOrFail();
        $question = $test->questions()->findOrFail($questionId);

        if ($question->answers()->count() >= 4) {
            return response()->json(['message' => 'Cannot add more than 4 answers'], Http::HTTP_BAD_REQUEST);
        }

        $validated = $request->validate([
            'answer'     => 'required|string|max:1000',
            'is_correct' => 'required|boolean',
        ]);

        if ($question->answers()->where('answer', $validated['answer'])->exists()) {
            return response()->json(['message' => 'This answer already exists for this question'], Http::HTTP_CONFLICT);
        }

        if (!$validated['is_correct'] && !$question->answers()->where('is_correct', true)->exists()) {
            return response()->json(['message' => 'You must add at least one correct answer first'], Http::HTTP_BAD_REQUEST);
        }

        $answer = $question->answers()->create($validated);
        // --- POPRAWKA: Zwracamy zagnieżdżony obiekt ---
        return response()->json(['answer' => $answer], Http::HTTP_CREATED);
    }

    public function getAnswersForQuestion($testId, $questionId): JsonResponse
    {
        $user = Auth::user();
        $test = Test::where('id', $testId)->where('user_id', $user->id)->firstOrFail();
        $question = $test->questions()->findOrFail($questionId);
        $answers = $question->answers()->orderBy('id')->get();
        // --- POPRAWKA: Zwracamy zagnieżdżony obiekt ---
        return response()->json(['answers' => $answers]);
    }

    public function updateAnswer(Request $request, $testId, $questionId, $answerId): JsonResponse
    {
        $user = Auth::user();
        $test = Test::where('id', $testId)->where('user_id', $user->id)->firstOrFail();
        $question = $test->questions()->findOrFail($questionId);
        $answer = $question->answers()->findOrFail($answerId);

        $validated = $request->validate([
            'answer'     => 'required|string|max:1000',
            'is_correct' => 'required|boolean',
        ]);

        if ($question->answers()->where('answer', $validated['answer'])->where('id', '!=', $answerId)->exists()) {
            return response()->json(['message' => 'Another answer with this text already exists'], Http::HTTP_CONFLICT);
        }

        if ($answer->is_correct && !$validated['is_correct'] && $question->answers()->where('is_correct', true)->count() <= 1) {
            return response()->json(['message' => 'Cannot remove the last correct answer'], Http::HTTP_BAD_REQUEST);
        }

        $answer->update($validated);
        // --- POPRAWKA: Zwracamy zagnieżdżony obiekt ---
        return response()->json(['answer' => $answer]);
    }

    public function destroyAnswer($testId, $questionId, $answerId): JsonResponse
    {
        $user = Auth::user();
        $test = Test::where('id', $testId)->where('user_id', $user->id)->firstOrFail();
        $question = $test->questions()->findOrFail($questionId);
        $answer = $question->answers()->findOrFail($answerId);

        if ($answer->is_correct && $question->answers()->where('is_correct', true)->count() <= 1) {
            return response()->json(['message' => 'Cannot delete the last correct answer'], Http::HTTP_BAD_REQUEST);
        }

        $answer->delete();
        return response()->json(['message' => 'Answer deleted successfully.'], Http::HTTP_OK);
    }

    // =================== COURSE TESTS (Logika bez zmian, tylko drobne poprawki) ===================

    public function indexForCourse(int $courseId): JsonResponse
    {
        $course = Course::find($courseId); // Użyj find zamiast findOrFail dla lepszej obsługi 404
        if (!$course) return response()->json(['error' => 'Course not found'], Http::HTTP_NOT_FOUND);

        $user = Auth::user();
        if (!$user) return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);

        $isOwner = ((int) $course->user_id === (int) $user->id);
        $isMember = DB::table('courses_users')
            ->where('course_id', $course->id)->where('user_id', $user->id)
            ->where('status', 'accepted')->exists();
        if (!$isOwner && !$isMember) {
            return response()->json(['error' => 'Forbidden'], Http::HTTP_FORBIDDEN); // Poprawiono status
        }

        $tests = $course->tests()
            ->with(['user:id,name,email', 'questions'])
            ->withCount('questions')
            ->orderBy('title')
            ->get();

        return response()->json($tests); // Zwracamy listę testów bezpośrednio
    }

    public function storeForCourse(Request $request, $courseId): JsonResponse
    {
        $course = Course::find($courseId);
        if (!$course) return response()->json(['error' => 'Course not found'], Http::HTTP_NOT_FOUND);

        $user = Auth::user();
        if (!$user) return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);

        $role = DB::table('courses_users')->where('course_id', $course->id)->where('user_id', $user->id)->value('role');
        $isCreator = ((int) $course->user_id === (int) $user->id);
        if (!$isCreator && !in_array($role, ['admin', 'moderator'])) {
            return response()->json(['error' => 'Only course admins/moderators can create tests directly in the course'], Http::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $test = null;
        DB::transaction(function () use ($user, $course, $validated, &$test) { // Przekazujemy $course
            $test = Test::create([
                'user_id'     => $user->id,
                'title'       => $validated['title'],
                'description' => $validated['description'] ?? null,
                'status'      => Test::STATUS_PUBLIC,
            ]);
            $course->tests()->attach($test->id); // Dołącz do kursu używając obiektu $course
        });

        // --- POPRAWKA: Zwracamy zagnieżdżony obiekt dla spójności ---
        return response()->json(['test' => $test->load('courses:id,title')], Http::HTTP_CREATED);
    }

    public function showForCourse($courseId, $testId): JsonResponse
    {
        // 1. Walidacja: Użytkownik i Autoryzacja
        $user = Auth::user();
        // Poprawka: Zapewnienie, że Auth::user() nie jest null.
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED); // Użycie stałej HTTP
        }

        // 2. Wyszukanie kursu
        $course = Course::find($courseId);
        if (!$course) {
            return response()->json(['error' => 'Course not found'], Http::HTTP_NOT_FOUND);
        }

        // 3. Sprawdzenie uprawnień
        $isOwner = ((int) $course->user_id === (int) $user->id);
        $isMember = DB::table('courses_users')
            ->where('course_id', $course->id)
            ->where('user_id', $user->id)
            ->where('status', 'accepted')
            ->exists();

        if (!$isOwner && !$isMember) {
            return response()->json(['error' => 'Forbidden - Not an owner or accepted member'], Http::HTTP_FORBIDDEN);
        }

        // 4. Pobranie testu z relacjami
        // Używamy course->tests() dla upewnienia się, że test należy do kursu
        $test = $course->tests()
            // Eager loading: Użytkownik testu, pytania testu i ich odpowiedzi, oraz kursy, do których test należy
            ->with([
                'user:id,name,email', // Dane twórcy testu
                'questions.answers',  // Pytania i zagnieżdżone odpowiedzi (wszystkie dane)
                'courses:id,title'    // Kursy, do których test jest przypisany
            ])
            ->find($testId);

        if (!$test) {
            return response()->json(['error' => 'Test not found within this course'], Http::HTTP_NOT_FOUND);
        }

        // 5. Zwrócenie pełnych danych testu
        return response()->json(['test' => $test]);
    }
    public function updateForCourse(Request $request, $courseId, $testId): JsonResponse
    {
        $course = Course::find($courseId);
        if (!$course) return response()->json(['error' => 'Course not found'], Http::HTTP_NOT_FOUND);

        $user = Auth::user();
        if (!$user) return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);

        // Znajdź test powiązany z kursem
        $test = $course->tests()->find($testId); // Użyj find
        if (!$test) return response()->json(['error' => 'Test not found within this course'], Http::HTTP_NOT_FOUND);

        // Autoryzacja: Tylko autor testu
        if ((int) $test->user_id !== (int) $user->id) {
            return response()->json(['error' => 'Only the test author can update it'], Http::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            // Status nie powinien być zmieniany tutaj, tylko w updateForUser
        ]);

        $test->update($validated);
        // --- POPRAWKA: Zwracamy zagnieżdżony obiekt dla spójności ---
        return response()->json(['test' => $test->load('courses:id,title')]);
    }

    public function destroyForCourse($courseId, $testId): JsonResponse
    {
        $course = Course::find($courseId);
        if (!$course) return response()->json(['error' => 'Course not found'], Http::HTTP_NOT_FOUND);

        $user = Auth::user();
        if (!$user) return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);

        $test = $course->tests()->find($testId); // Użyj find
        if (!$test) return response()->json(['error' => 'Test not found within this course'], Http::HTTP_NOT_FOUND);

        $role = DB::table('courses_users')->where('course_id', $course->id)->where('user_id', $user->id)->value('role');
        $isCreator = ((int) $course->user_id === (int) $user->id);
        $isAuthor = ((int) $test->user_id === (int) $user->id);
        if (!$isAuthor && !$isCreator && !in_array($role, ['admin', 'moderator'])) {
            return response()->json(['error' => 'Unauthorized to delete this test'], Http::HTTP_FORBIDDEN);
        }

        // Nie trzeba detach, kaskada działa
        $test->delete();

        return response()->json(['message' => 'Test deleted successfully.'], Http::HTTP_OK);
    }


    // =================== SHARE TEST -> COURSE (Poprawiona struktura odpowiedzi) ===================

    public function shareTestWithCourse(Request $request, $testId): JsonResponse
    {
        $user = Auth::user();
        if (!$user) return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);

        $validated = $request->validate(['course_id' => 'required|integer|exists:courses,id']); // Użyj integer
        $courseId = $validated['course_id'];

        $test = Test::where('id', $testId)->where('user_id', $user->id)->first(); // Użyj first
        if (!$test) return response()->json(['error' => 'Test not found or not owned by you'], Http::HTTP_NOT_FOUND);


        if ($test->status !== Test::STATUS_PUBLIC) {
            // --- POPRAWKA: Zmieniamy status testu na publiczny automatycznie ---
            $test->status = Test::STATUS_PUBLIC;
            $test->save();
            //return response()->json(['message' => 'Test must be public to be shared.'], Http::HTTP_UNPROCESSABLE_ENTITY); // 422
        }

        $course = Course::find($courseId); // Użyj find
        if (!$course) return response()->json(['error' => 'Course not found'], Http::HTTP_NOT_FOUND);

        $isOwner = ((int) $course->user_id === (int) $user->id);
        $isMember = DB::table('courses_users')
            ->where('course_id', $course->id)->where('user_id', $user->id)
            ->where('status', 'accepted')->exists();
        if (!$isOwner && !$isMember) {
            return response()->json(['message' => 'You must be a member of the course to share tests.'], Http::HTTP_FORBIDDEN);
        }

        // --- POPRAWKA: Używamy syncWithoutDetaching dla idempotencji ---
        $test->courses()->syncWithoutDetaching([$courseId]);
        $test->load('courses:id,title'); // Odśwież relacje

        // --- POPRAWKA: Zwracamy zagnieżdżony obiekt dla spójności ---
        return response()->json(['message' => 'Test shared successfully with the course.', 'test' => $test], Http::HTTP_OK);
    }

    public function unShareTestWithCourse(Request $request, $testId): JsonResponse
    {
        $user = Auth::user();
        if (!$user) return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);

        $validated = $request->validate([
            'course_id' => 'required|integer|exists:courses,id',
        ]);
        $courseId = $validated['course_id'];

        $test = Test::with('courses:id') // Załaduj tylko ID kursów
        ->where('id', $testId)
            ->where('user_id', $user->id)
            ->first();

        if (!$test) {
            return response()->json(['error' => 'Test not found or you do not own this test.'], Http::HTTP_NOT_FOUND);
        }

        // Sprawdzenie kursu nie jest konieczne dzięki walidacji 'exists'
        // $course = Course::find($courseId);
        // if (!$course) ...

        $isAttached = $test->courses->contains($courseId); // Sprawdź po ID

        if (!$isAttached) {
            // --- POPRAWKA: Zwracamy zagnieżdżony obiekt dla spójności ---
            return response()->json([
                'message' => 'Test is not currently shared with this course.',
                'test'    => $test->load('courses:id,title') // Dociążamy pełne dane dla odpowiedzi
            ], Http::HTTP_OK); // Nadal OK, bo stan docelowy osiągnięty
        }

        $test->courses()->detach($courseId);
        $test->load('courses:id,title'); // Odśwież relację w załadowanym modelu

        // --- POPRAWKA: Zwracamy zagnieżdżony obiekt dla spójności ---
        return response()->json([
            'message' => 'Test has been unshared from the course successfully.',
            'test'    => $test
        ], Http::HTTP_OK);
    }
}
