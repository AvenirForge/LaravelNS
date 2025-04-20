<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Test;
use App\Models\TestsQuestion;
use App\Models\TestsAnswer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class TestController extends Controller
{
    // =================== PRIVATE TESTS ===================

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
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'status' => 'required|in:private,public',
        ]);

        $test = Test::create([
            'user_id' => $user->id,
            'course_id' => null, // test prywatny
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'],
        ]);

        return response()->json($test, 201);
    }

    public function showForUser($id)
    {
        $test = Test::where('id', $id)->where('user_id', Auth::user()->id)->with('questions.answers')->firstOrFail();
        return response()->json($test);
    }

    public function updateForUser(Request $request, $id)
    {
        $test = Test::where('id', $id)->where('user_id', Auth::user()->id)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);

        $test->update($request->only(['title', 'description']));

        return response()->json($test);
    }

    public function destroyForUser($id)
    {
        $test = Test::where('id', $id)->where('user_id', Auth::user()->id)->firstOrFail();
        $test->delete();

        return response()->json(['message' => 'Test deleted.']);
    }

    public function questionsForUser($testId)
    {
        $user = Auth::user();
        $test = Test::where('id', $testId)
            ->where('user_id', $user->id)
            ->whereNull('course_id')
            ->firstOrFail();

        $questions = $test->questions()->with('answers')->get();

        return response()->json(['questions' => $questions], 200);
    }

    // =================== COURSE TESTS ===================

    public function indexForCourse($courseId)
    {
        $tests = Test::where('course_id', $courseId)->with('questions.answers')->get();
        return response()->json($tests);
    }

    public function storeForCourse(Request $request, $courseId)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);

        $test = Test::create([
            'user_id' => Auth::user()->id,
            'title' => $request->title,
            'description' => $request->description,
            'course_id' => $courseId,
        ]);

        return response()->json($test, 201);
    }

    public function showForCourse($courseId, $testId)
    {
        $test = Test::where('id', $testId)->where('course_id', $courseId)->with('questions.answers')->firstOrFail();
        return response()->json($test);
    }

    public function updateForCourse(Request $request, $courseId, $testId)
    {
        $test = Test::where('id', $testId)->where('course_id', $courseId)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);

        $test->update($request->only(['title', 'description']));

        return response()->json($test);
    }

    public function destroyForCourse($courseId, $testId)
    {
        $test = Test::where('id', $testId)->where('course_id', $courseId)->firstOrFail();
        $test->delete();

        return response()->json(['message' => 'Test deleted.']);
    }

    // =================== QUESTIONS ===================

    public function storeQuestion(Request $request, $testId)
    {
        $test = Test::where('id', $testId)
            ->where('user_id', Auth::user()->id)
            ->firstOrFail();

        // Sprawdzenie, czy test ma już 20 pytań
        $questionCount = TestsQuestion::where('test_id', $test->id)->count();
        if ($questionCount >= 20) {
            return response()->json([
                'message' => 'Nie można dodać więcej niż 20 pytań do jednego testu.'
            ], 400);
        }

        // Walidacja
        $validator = Validator::make($request->all(), [
            'question' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Tworzenie pytania
        $question = TestsQuestion::create([
            'test_id' => $test->id,
            'question' => $request->question,
        ]);

        return response()->json($question, 201);
    }

    public function updateQuestion(Request $request, $testId, $questionId)
    {
        $question = TestsQuestion::where('id', $questionId)->where('test_id', $testId)->firstOrFail();
        $test = Test::where('id', $testId)->where('user_id', Auth::user()->id)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'question' => 'required|string',
        ]);

        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);

        $question->update(['question' => $request->question]);

        return response()->json($question);
    }

    public function destroyQuestion($testId, $questionId)
    {
        $question = TestsQuestion::where('id', $questionId)->where('test_id', $testId)->firstOrFail();
        $test = Test::where('id', $testId)->where('user_id', Auth::user()->id)->firstOrFail();

        $question->delete();

        return response()->json(['message' => 'Question deleted.']);
    }

    // =================== ANSWERS ===================
    public function storeAnswer(Request $request, $testId, $questionId)
    {
        $user = Auth::user();

        // Sprawdzenie, czy test należy do użytkownika
        $test = Test::where('user_id', $user->id)->findOrFail($testId);

        // Sprawdzenie, czy pytanie istnieje w tym teście
        $question = TestsQuestion::where('test_id', $test->id)->findOrFail($questionId);

        // Sprawdzenie, ile odpowiedzi użytkownik już dodał do tego pytania
        $existingAnswersCount = TestsAnswer::where('question_id', $question->id)
            ->count();

        // Ograniczenie do 4 odpowiedzi na pytanie
        if ($existingAnswersCount >= 4) {
            return response()->json(['message' => 'Możesz dodać maksymalnie 4 odpowiedzi'], 400);
        }

        // Walidacja danych odpowiedzi
        $validated = $request->validate([
            'answer' => 'required|string|max:1000',
            'is_correct' => 'required|boolean',  // Sprawdzamy, czy odpowiedź jest poprawna
        ]);

        // Sprawdzamy, czy odpowiedź jest już zapisana
        $existingAnswer = TestsAnswer::where('question_id', $question->id)
            ->where('answer', $validated['answer'])
            ->first();

        if ($existingAnswer) {
            return response()->json(['message' => 'Taka odpowiedź już istnieje'], 400);
        }

        // Sprawdzamy, czy w pytaniu jest już przynajmniej jedna poprawna odpowiedź
        $existingCorrectAnswer = TestsAnswer::where('question_id', $question->id)
            ->where('is_correct', true)
            ->first();

        if ($validated['is_correct']) {
            // Jeśli odpowiedź jest poprawna, sprawdzamy, czy już istnieje odpowiedź poprawna
            if (!$existingCorrectAnswer) {
                // Jeśli nie ma żadnej poprawnej odpowiedzi, zapisujemy tę odpowiedź jako poprawną
                $validated['is_correct'] = true;
            }
        } else {
            // Jeżeli odpowiedź nie jest poprawna, ale jeszcze nie ma żadnej poprawnej,
            // nie pozwalamy dodać odpowiedzi, ponieważ przynajmniej jedna musi być poprawna
            if (!$existingCorrectAnswer) {
                return response()->json(['message' => 'Musisz dodać przynajmniej jedną poprawną odpowiedź'], 400);
            }
        }

        // Tworzenie odpowiedzi
        $answer = TestsAnswer::create([
            'question_id' => $question->id,
            'answer' => $validated['answer'],
            'is_correct' => $validated['is_correct'], // Zapisujemy informację o poprawności
        ]);

        return response()->json(['message' => 'Odpowiedź została zapisana', 'answer' => $answer], 201);
    }
    public function getAnswersForQuestion($testId, $questionId)
    {
        $user = Auth::user();

        // Sprawdzenie czy test należy do użytkownika
        $test = Test::where('id', $testId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        // Sprawdzenie czy pytanie należy do tego testu
        $question = TestsQuestion::where('id', $questionId)
            ->where('test_id', $test->id)
            ->firstOrFail();

        // Pobranie odpowiedzi powiązanych z pytaniem
        $answers = TestsAnswer::where('question_id', $question->id)->get();

        if ($answers->isEmpty()) {
            return response()->json(['message' => 'Brak odpowiedzi dla tego pytania.'], 404);
        }

        return response()->json($answers);
    }

    public function updateAnswer(Request $request, $testId, $questionId, $answerId)
    {
        $answer = TestsAnswer::where('id', $answerId)->where('question_id', $questionId)->firstOrFail();
        $question = TestsQuestion::where('id', $questionId)->where('test_id', $testId)->firstOrFail();
        $test = Test::where('id', $testId)->where('user_id', Auth::user()->id)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'answer' => 'required|string',
            'is_correct' => 'required|boolean',
        ]);

        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);

        $answer->update([
            'answer' => $request->answer,
            'is_correct' => $request->is_correct,
        ]);

        return response()->json($answer);
    }

    public function destroyAnswer($testId, $questionId, $answerId)
    {
        $answer = TestsAnswer::where('id', $answerId)->where('question_id', $questionId)->firstOrFail();
        $question = TestsQuestion::where('id', $questionId)->where('test_id', $testId)->firstOrFail();
        $test = Test::where('id', $testId)->where('user_id', Auth::user()->id)->firstOrFail();

        $answer->delete();

        return response()->json(['message' => 'Answer deleted.']);
    }


    public function shareTestWithCourse(Request $request, $testId): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'course_id' => 'required|exists:courses,id',
        ]);

        // Pobierz test użytkownika
        $test = Test::where('id', $testId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        // Sprawdź status testu
        if ($test->status !== 'public') {
            return response()->json(['message' => 'Test musi być publiczny, aby go udostępnić.'], 403);
        }

        // Sprawdź, czy użytkownik jest członkiem kursu
        $isCourseMember = DB::table('course_user')
            ->where('course_id', $validated['course_id'])
            ->where('user_id', $user->id)
            ->exists();

        if (!$isCourseMember) {
            return response()->json(['message' => 'Nie jesteś członkiem wybranego kursu.'], 403);
        }

        // Udostępnienie testu w kursie
        $test->course_id = $validated['course_id'];
        $test->save();

        return response()->json([
            'message' => 'Test został udostępniony w kursie.',
            'test' => $test
        ], 200);
    }

}
