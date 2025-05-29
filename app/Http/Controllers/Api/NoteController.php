<?php

namespace App\Http\Controllers\Api;

use App\Models\Note;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class NoteController extends Controller
{
    /**
     * Display a listing of notes for the authenticated user.
     */
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();

        // Odczyt parametrów paginacji z zapytania
        $top = (int) $request->query('top', 10);   // ile rekordów zwrócić (domyślnie 10)
        $skip = (int) $request->query('skip', 0);  // ile pominąć (domyślnie 0)

        // Pobranie notatek z relacji z użyciem paginacji
        $notes = $user->notes()
            ->skip($skip)
            ->take($top)
            ->get();

        return response()->json([
            'data' => $notes,
            'skip' => $skip,
            'top' => $top,
            'count' => $user->notes()->count()
        ]);
    }
    /**
     * Store a newly created note.
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'file' => 'required|file|mimes:pdf,xlsx,jpg,jpeg,png|max:2048',
            'is_private' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $isPrivate = $request->input('is_private', true);

        if (!$request->hasFile('file')) {
            return response()->json(['error' => 'No file uploaded'], 400);
        }

        $note = new Note();
        $note->title = $request->input('title');
        $note->description = $request->input('description');
        $note->is_private = $isPrivate;
        $note->user_id = $user->id;

        // Zapis pliku w public/storage/users/notes
        $file = $request->file('file');
        $filePath = $file->store('users/notes', 'public'); // <-- użycie dysku 'public'
        $note->file_path = $filePath;

        $note->save();

        return response()->json([
            'message' => 'Note created successfully!',
            'note' => $note,
        ]);
    }


    /**
     * Display the specified note.
     */
    public function show($id): \Illuminate\Http\JsonResponse
    {
        $note = Note::find($id);

        if (!$note) {
            return response()->json(['error' => 'Note not found'], 404);
        }

        if ($note->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return response()->json($note);
    }

    /**
     * Update the specified note.
     */
    public function updateContent(Request $request, $id): \Illuminate\Http\JsonResponse
    {
        $note = Note::find($id);

        if (!$note) {
            return response()->json(['error' => 'Note not found'], 404);
        }

        if ($note->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_private' => 'nullable|boolean',
        ]);

        $note->update($request->only('title', 'description', 'is_private'));

        return response()->json([
            'message' => 'Note text updated successfully!',
            'note' => $note,
        ]);
    }
    public function replaceFile(Request $request, $id): \Illuminate\Http\JsonResponse
    {
        $note = Note::find($id);

        if (!$note) {
            return response()->json(['error' => 'Note not found'], 404);
        }

        if ($note->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'file' => 'required|file|max:2048|mimes:jpg,jpeg,png,pdf,doc,docx',
        ], [
            'file.required' => 'Nie dodano pliku.',
            'file.mimes' => 'Dozwolone formaty to jpg, png, pdf, doc, docx.',
            'file.max' => 'Plik nie może być większy niż 2MB.',
        ]);

        // Usuń stary plik, jeśli istnieje
        if ($note->file_path && Storage::disk('public')->exists($note->file_path)) {
            Storage::disk('public')->delete($note->file_path);
        }

        // Zapisz nowy plik do 'public/users/notes'
        $filePath = $request->file('file')->store('users/notes', 'public');
        $note->file_path = $filePath;
        $note->save();

        return response()->json([
            'message' => 'Note file updated successfully!',
            'note' => $note,
        ]);
    }


    /**
     * Remove the specified note.
     */

    public function shareNoteWithCourse(Request $request, $noteId, $courseId): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();

        // Sprawdzenie, czy notatka należy do użytkownika
        $note = Note::where('user_id', $user->id)->findOrFail($noteId);

        // Sprawdzenie, czy kurs istnieje
        $course = Course::findOrFail($courseId);

        // Zaktualizowanie statusu notatki na publiczną i przypisanie do kursu
        $note->update([
            'status' => 'public', // Zmieniamy status na publiczny
            'course_id' => $courseId,
        ]);

        return response()->json(['message' => 'Notatka udostępniona w kursie'], 200);
    }

    public function destroy($id): \Illuminate\Http\JsonResponse
    {
        $user = auth()->user();

        $note = Note::find($id);

        if (!$note) {
            return response()->json(['error' => 'Note not found'], 404);
        }

        if ($note->user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($note->file_path && Storage::exists($note->file_path)) {
            Storage::delete($note->file_path);
        }

        $note->delete();

        return response()->json(['message' => 'Note deleted successfully']);
    }

    public function download($id): \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\JsonResponse
    {
        $note = Note::find($id);

        if (!$note) {
            return response()->json(['error' => 'Note not found'], 404);
        }

        $user = auth()->user();

        if ($note->user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $filePath = storage_path("app/public/{$note->file_path}");

        if (!file_exists($filePath)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        return response()->download($filePath);
    }


}
