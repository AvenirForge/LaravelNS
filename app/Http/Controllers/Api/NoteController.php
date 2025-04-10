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
    public function index(): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();
        $notes = $user->notes; // Pobierz notki powiązane z użytkownikiem

        return response()->json($notes);
    }

    /**
     * Store a newly created note.
     */
    public function store(Request $request)
    {
        // Pobieramy aktualnie zalogowanego użytkownika
        $user = auth()->user();

        // Walidacja danych
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'file' => 'required|file|mimes:pdf,xlsx,jpg,jpeg,png|max:2048', // Dodajemy walidację, aby plik był wymagany
            'is_private' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        // Jeśli is_private nie jest w danych, domyślnie ustawiamy je na true
        $isPrivate = $request->input('is_private', true);

        // Sprawdzanie, czy plik został przesłany
        if (!$request->hasFile('file')) {
            return response()->json(['error' => 'No file uploaded'], 400);  // Zwrócenie błędu, jeśli nie ma pliku
        }

        // Tworzenie notatki
        $note = new Note();
        $note->title = $request->input('title');
        $note->description = $request->input('description');
        $note->is_private = $isPrivate;
        $note->user_id = $user->id; // Dodajemy ID użytkownika

        // Obsługa pliku
        $file = $request->file('file');
        $filePath = $file->store('notes_files'); // Przechowujemy plik na serwerze w folderze 'notes_files'
        $note->file_path = $filePath; // Zapisywanie ścieżki do bazy danych

        // Zapisanie notatki do bazy danych
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
    public function update(Request $request, $id): \Illuminate\Http\JsonResponse
    {
        $note = Note::find($id);

        if (!$note) {
            return response()->json(['error' => 'Note not found'], 404);
        }

        if ($note->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $note->update($request->only('title', 'description', 'is_private'));

        if ($request->hasFile('file')) {
            Storage::delete($note->file_path);
            $filePath = $request->file('file')->store('notes');
            $note->file_path = $filePath;
        }

        return response()->json(['message' => 'Note updated successfully!', 'note' => $note]);
    }

    /**
     * Remove the specified note.
     */
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


}
