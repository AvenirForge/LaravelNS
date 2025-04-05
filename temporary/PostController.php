<?php
namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class PostController extends Controller
{
    public function index($userId, $courseId)
    {
        // Sprawdzamy, czy użytkownik ma dostęp do tego kursu
        $user = auth()->user();

        if ($user->id != $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Sprawdzamy, czy kurs należy do użytkownika
        $course = $user->course()->where('id', $courseId)->first();

        if (!$course) {
            return response()->json(['error' => 'Course not found or not accessible'], 404);
        }

        // Pobieramy wszystkie notatki związane z tym kursem
        $notes = $course->notes;

        // Zwracamy notatki w odpowiedzi
        return response()->json($notes);
    }
    // Pobieranie szczegółów posta
    public function show($groupId, $postId)
    {
        // Sprawdzenie, czy grupa istnieje
        $group = Group::findOrFail($groupId);

        // Sprawdzenie, czy użytkownik jest członkiem grupy
        if (!$group->users->contains(Auth::id())) {
            return response()->json(['error' => 'You are not a member of this group'], 403);
        }

        // Znalezienie posta w danej grupie
        $post = $group->posts()->findOrFail($postId);

        // Zwrócenie posta
        return response()->json($post);
    }
    // Dodawanie nowego posta
    public function store(Request $request, $groupId)
    {
        // Walidacja danych wejściowych
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:2048', // Obsługuje pliki typu jpg, jpeg, png, pdf
        ]);

        // Sprawdzenie, czy grupa istnieje
        $group = Group::findOrFail($groupId);

        // Upewnij się, że użytkownik jest członkiem grupy
        if (!$group->users->contains(Auth::id())) {
            return response()->json(['error' => 'You are not a member of this group'], 403);
        }

        // Zapisz plik i uzyskaj ścieżkę
        $filePath = (new Post())->uploadFile($request->file('file'));

        // Utwórz nowy post
        $post = Post::create([
            'user_id' => Auth::id(),
            'group_id' => $group->id,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'file_path' => $filePath,
        ]);

        return response()->json($post, 201);
    }

    // Usuwanie posta
    public function destroy($groupId, $postId)
    {
        $group = Group::findOrFail($groupId);
        $post = $group->posts()->findOrFail($postId);

        // Sprawdzamy, czy użytkownik jest właścicielem posta
        if ($post->user_id != Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Usuwamy plik z serwera
        if ($post->file_path && Storage::disk('public')->exists($post->file_path)) {
            Storage::disk('public')->delete($post->file_path);
        }

        // Usuwamy post z bazy danych
        $post->delete();

        return response()->json(['message' => 'Post deleted successfully'], 204);
    }

    // Edytowanie posta
    public function update(Request $request, $groupId, $postId)
    {
        $group = Group::findOrFail($groupId);
        $post = $group->post()->findOrFail($postId);

        // Sprawdzamy, czy użytkownik jest właścicielem posta
        if ($post->user_id != Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Walidacja danych wejściowych
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'file' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
        ]);

        // Zapisujemy nowy plik, jeśli jest
        if ($request->hasFile('file')) {
            // Usuwamy stary plik
            if ($post->file_path && Storage::disk('public')->exists($post->file_path)) {
                Storage::disk('public')->delete($post->file_path);
            }

            // Zapisujemy nowy plik
            $filePath = (new Post())->uploadFile($request->file('file'));
            $post->file_path = $filePath;
        }

        // Aktualizujemy dane posta
        $post->update([
            'title' => $validated['title'],
            'description' => $validated['description'],
        ]);

        return response()->json($post);
    }
}
