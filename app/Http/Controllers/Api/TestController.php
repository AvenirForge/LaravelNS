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
use Illuminate\Support\Facades\Schema;

class TestController extends Controller
{
    // =================== PRIVATE TESTS (ME) ===================

    public function indexForUser()
    {
        $user = Auth::user();

        $tests = Test::where('user_id', $user->id)
            ->whereNull('course_id')
            ->withCount('questions')
            ->latest()
            ->get();

        return response()->json($tests);
    }

    public function storeForUser(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'status'      => 'required|in:private,public',
        ]);

        $test = Test::create([
            'user_id'     => $user->id,
            'course_id'   => null, // prywatny
            'title'       => $validated['title'],
            'description' => $validated['description'] ?? null,
            'status'      => $validated['status'],
        ]);

        return response()->json($test, 201);
    }

    public function showForUser($id)
    {
        $test = Test::where('id', $id)
            ->where('user_id', Auth::user()->id)
            ->with('questions.answers')
            ->firstOrFail();

        return response()->json($test);
    }

    public function updateForUser(Request $request, $id)
    {
        $test = Test::where('id', $id)
            ->where('user_id', Auth::user()->id)
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $test->update($request->only(['title', 'description']));

        return response()->json($test);
    }

    public function destroyForUser($id)
    {
        $test = Test::where('id', $id)
            ->where('user_id', Auth::user()->id)
            ->firstOrFail();

        $test->delete();

        return response()->json(['message' => 'Test deleted.']);
    }

    /**
     * GET /api/me/tests/{testId}/questions
     * Płaskie tablice + jawne pola (stabilna serializacja).
     */
    public function questionsForUser($testId)
    {
        $user = Auth::user();

        $test = Test::where('id', $testId)
            ->where('user_id', $user->id)
            ->whereNull('course_id')
            ->firstOrFail();

        $questions = TestsQuestion::where('test_id', $test->id)
            ->orderBy('id')
            ->get()
            ->map(function (TestsQuestion $q) {
                $answers = TestsAnswer::where('question_id', $q->id)
                    ->orderBy('id')
                    ->get(['id','question_id','answer','is_correct','created_at','updated_at'])
                    ->map(function (TestsAnswer $a) {
                        return [
                            'id'          => $a->id,
                            'question_id' => $a->question_id,
                            'answer'      => $a->answer,
                            'is_correct'  => (bool) $a->is_correct,
                            'created_at'  => $a->created_at,
                            'updated_at'  => $a->updated_at,
                        ];
                    })->all();

                return [
                    'id'         => $q->id,
                    'test_id'    => $q->test_id,
                    'question'   => $q->question,
                    'created_at' => $q->created_at,
                    'updated_at' => $q->updated_at,
                    'answers'    => $answers,
                ];
            })->all();

        return response()->json(['questions' => $questions], 200);
    }

    // =================== COURSE TESTS ===================

    /**
     * GET /api/courses/{courseId}/tests
     * Agregacja: tests.course_id + legacy pivot course_test (jeśli istnieje).
     */
    public function indexForCourse(int $courseId): JsonResponse
    {
        // Wspólny eager-load – poprawiona nazwa relacji
        $with = [
            'user:id,name,email',                           // autor testu
            'course:id,title,type,user_id,avatar',         // POPRAWKA: 'course' (poj.) zamiast 'courses' (mn.)
            'questions.answers',                           // pytania + odpowiedzi
        ];

        // 1) Pobieramy TYLKO testy przypięte bezpośrednio do kursu,
        //    ponieważ migracje nie definiują innej metody powiązania (jak pivot table).
        $tests = Test::query()
            ->where('course_id', $courseId)
            ->with($with)
            ->orderBy('id') // Opcjonalne sortowanie
            ->get();

        // 2) Logika dla '$shared', 'Schema::hasTable' i 'concat' została usunięta,
        //    ponieważ tabela 'course_test' nie istnieje i nie ma relacji N:N.
        //    Jedno zapytanie jest wystarczające.

        return response()->json($tests);
    }
    public function storeForCourse(Request $request, $courseId)
    {
        $validator = Validator::make($request->all(), [
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);

        $test = Test::create([
            'user_id'     => Auth::user()->id,
            'course_id'   => $courseId,
            'title'       => $request->title,
            'description' => $request->description,
            'status'      => Test::STATUS_PRIVATE, // ★ tu wymagało stałej
        ]);

        return response()->json($test, 201);
    }

    public function showForCourse($courseId, $testId)
    {
        $test = Test::where('id', $testId)
            ->where('course_id', $courseId)
            ->with('questions.answers')
            ->firstOrFail();

        return response()->json($test);
    }

    public function updateForCourse(Request $request, $courseId, $testId)
    {
        $test = Test::where('id', $testId)
            ->where('course_id', $courseId)
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);

        $test->update($request->only(['title', 'description']));

        return response()->json($test);
    }

    public function destroyForCourse($courseId, $testId)
    {
        $test = Test::where('id', $testId)
            ->where('course_id', $courseId)
            ->firstOrFail();

        $test->delete();

        return response()->json(['message' => 'Test deleted.']);
    }

    // =================== QUESTIONS ===================

    public function storeQuestion(Request $request, $testId)
    {
        $test = Test::where('id', $testId)
            ->where('user_id', Auth::user()->id)
            ->firstOrFail();

        // Limit 20 pytań
        $questionCount = TestsQuestion::where('test_id', $test->id)->count();
        if ($questionCount >= 20) {
            return response()->json([
                'message' => 'Nie można dodać więcej niż 20 pytań do jednego testu.'
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'question' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $question = TestsQuestion::create([
            'test_id'  => $test->id,
            'question' => $request->question,
        ]);

        return response()->json($question, 201);
    }

    public function updateQuestion(Request $request, $testId, $questionId)
    {
        $question = TestsQuestion::where('id', $questionId)
            ->where('test_id', $testId)
            ->firstOrFail();

        // autoryzacja właściciela testu
        $test = Test::where('id', $testId)
            ->where('user_id', Auth::user()->id)
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'question' => 'required|string',
        ]);

        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);

        $question->update(['question' => $request->question]);

        return response()->json($question);
    }

    public function destroyQuestion($testId, $questionId)
    {
        $question = TestsQuestion::where('id', $questionId)
            ->where('test_id', $testId)
            ->firstOrFail();

        // autoryzacja właściciela
        $test = Test::where('id', $testId)
            ->where('user_id', Auth::user()->id)
            ->firstOrFail();

        $question->delete();

        return response()->json(['message' => 'Question deleted.']);
    }

    // =================== ANSWERS ===================

    public function storeAnswer(Request $request, $testId, $questionId)
    {
        $user = Auth::user();

        $test = Test::where('user_id', $user->id)->findOrFail($testId);
        $question = TestsQuestion::where('test_id', $test->id)->findOrFail($questionId);

        $existingAnswersCount = TestsAnswer::where('question_id', $question->id)->count();
        if ($existingAnswersCount >= 4) {
            return response()->json(['message' => 'Możesz dodać maksymalnie 4 odpowiedzi'], 400);
        }

        $validated = $request->validate([
            'answer'     => 'required|string|max:1000',
            'is_correct' => 'required|boolean',
        ]);

        $existingAnswer = TestsAnswer::where('question_id', $question->id)
            ->where('answer', $validated['answer'])
            ->first();
        if ($existingAnswer) {
            return response()->json(['message' => 'Taka odpowiedź już istnieje'], 400);
        }

        $existingCorrectAnswer = TestsAnswer::where('question_id', $question->id)
            ->where('is_correct', true)
            ->first();

        if (!$validated['is_correct'] && !$existingCorrectAnswer) {
            return response()->json(['message' => 'Musisz dodać przynajmniej jedną poprawną odpowiedź'], 400);
        }

        $answer = TestsAnswer::create([
            'question_id' => $question->id,
            'answer'      => $validated['answer'],
            'is_correct'  => (bool) $validated['is_correct'],
        ]);

        return response()->json([
            'message' => 'Odpowiedź została zapisana',
            'answer'  => $answer
        ], 201);
    }

    public function getAnswersForQuestion($testId, $questionId)
    {
        $user = Auth::user();

        $test = Test::where('id', $testId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $question = TestsQuestion::where('id', $questionId)
            ->where('test_id', $test->id)
            ->firstOrFail();

        $answers = TestsAnswer::where('question_id', $question->id)
            ->select('id','question_id','answer','is_correct','created_at','updated_at')
            ->get();

        if ($answers->isEmpty()) {
            return response()->json(['message' => 'Brak odpowiedzi dla tego pytania.'], 404);
        }

        return response()->json($answers);
    }

    public function updateAnswer(Request $request, $testId, $questionId, $answerId)
    {
        $answer = TestsAnswer::where('id', $answerId)
            ->where('question_id', $questionId)
            ->firstOrFail();

        $question = TestsQuestion::where('id', $questionId)
            ->where('test_id', $testId)
            ->firstOrFail();

        $test = Test::where('id', $testId)
            ->where('user_id', Auth::user()->id)
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'answer'     => 'required|string',
            'is_correct' => 'required|boolean',
        ]);

        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);

        $answer->update([
            'answer'     => $request->answer,
            'is_correct' => (bool) $request->is_correct,
        ]);

        return response()->json($answer);
    }

    public function destroyAnswer($testId, $questionId, $answerId)
    {
        $answer = TestsAnswer::where('id', $answerId)
            ->where('question_id', $questionId)
            ->firstOrFail();

        $question = TestsQuestion::where('id', $questionId)
            ->where('test_id', $testId)
            ->firstOrFail();

        $test = Test::where('id', $testId)
            ->where('user_id', Auth::user()->id)
            ->firstOrFail();

        $answer->delete();

        return response()->json(['message' => 'Answer deleted.']);
    }

    // =================== SHARE TEST -> COURSE ===================

    public function shareTestWithCourse(Request $request, $testId): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'course_id' => 'required|exists:courses,id',
        ]);

        // Test użytkownika
        $test = Test::where('id', $testId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        // Test musi być publiczny
        if ($test->status !== Test::STATUS_PUBLIC) {
            return response()->json(['message' => 'Test musi być publiczny, aby go udostępnić.'], 403);
        }

        $course = Course::findOrFail($validated['course_id']);

        // Owner OR member
        $isOwner  = ((int) $course->user_id === (int) $user->id);
        $isMember = DB::table('courses_users')
            ->where('course_id', $course->id)
            ->where('user_id', $user->id)
            ->exists();

        if (!$isOwner && !$isMember) {
            return response()->json(['message' => 'Nie jesteś członkiem wybranego kursu.'], 403);
        }

        $test->course_id = $course->id;
        $test->save();

        return response()->json([
            'message' => 'Test został udostępniony w kursie.',
            'test'    => $test
        ], 200);
    }
}
