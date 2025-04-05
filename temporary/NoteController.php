<?php

namespace App\Http\Controllers;

use App\Models\Note;
use App\Models\Course;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NoteController extends Controller
{

    public function index($userId, $courseId): \Illuminate\Http\JsonResponse
    {
        $user = auth()->user();

        if ($user->id != $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $course = $user->course()->where('id', $courseId)->firstOrFail();
        $notes = $course->note;

        return response()->json($notes);
    }
    public function show($userId, $courseId, $noteId): \Illuminate\Http\JsonResponse
    {
        // Sprawdzenie, czy użytkownik istnieje
        $user = User::findOrFail($userId);

        // Pobranie kursu oraz sprawdzenie, czy należy do użytkownika
        $course = Course::where('id', $courseId)->where('user_id', $user->id)->first();

        if (!$course) {
            return response()->json(['error' => 'Unauthorized or course not found'], 403);
        }

        // Pobranie notatki dla danego kursu
        $note = Note::where('id', $noteId)->where('course_id', $courseId)->first();

        if (!$note) {
            return response()->json(['error' => 'Note not found'], 404);
        }

        return response()->json($note);
    }
    public function update(Request $request, $userId, $courseId, $noteId): \Illuminate\Http\JsonResponse
    {
        // Sprawdzenie, czy użytkownik może zaktualizować notatkę
        $user = auth()->user();

        if ($user->id != $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Sprawdzenie, czy kurs istnieje i należy do użytkownika
        $course = $user->course()->where('id', $courseId)->first();

        if (!$course) {
            return response()->json(['error' => 'Course not found or not accessible'], 404);
        }

        // Pobranie notatki przypisanej do kursu
        $note = Note::where('id', $noteId)->where('course_id', $courseId)->first();

        // Jeśli notatka nie została znaleziona, zwróć błąd
        if (!$note) {
            return response()->json(['error' => 'Note not found'], 404);
        }

        // Walidacja danych wejściowych
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'file_path' => 'nullable|file|mimes:png,jpg,pdf,doc,docx|max:2048',
        ]);

        // Jeśli tytuł został przekazany, zaktualizuj go
        if (!empty($validated['title'])) {
            $note->title = $validated['title'];
        }

        // Jeśli został przesłany nowy plik, zapisz go i zaktualizuj ścieżkę
        if ($request->hasFile('file_path')) {
            $filePath = $request->file('file_path')->store('notes', 'public');
            $note->file_path = $filePath;
        }

        // Zapisujemy zmiany w notatce
        $note->save();

        // Zwracamy zaktualizowaną notatkę
        return response()->json($note);
    }

    public function destroy($userId, $courseId, $noteId): \Illuminate\Http\JsonResponse
    {
        // Sprawdzenie, czy zalogowany użytkownik jest tym samym użytkownikiem
        $user = auth()->user();

        if ($user->id != $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Sprawdzenie, czy kurs istnieje i należy do użytkownika
        $course = $user->course()->where('id', $courseId)->first();

        if (!$course) {
            return response()->json(['error' => 'Course not found or not accessible'], 404);
        }

        // Sprawdzenie, czy notatka istnieje w kursie
        $note = $course->note()->where('id', $noteId)->first();

        if (!$note) {
            return response()->json(['error' => 'Note not found'], 404);
        }

        // Usuwamy notatkę
        $note->delete();

        return response()->json(['message' => 'Note deleted successfully'], 204);
    }

}
