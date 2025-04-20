<?php

// app/Http/Controllers/PostController.php

namespace App\Http\Controllers\Api;

namespace App\Http\Controllers\Api;
use App\Models\Post;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;



class PostController extends Controller
{

    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        // Pobieranie postów użytkownika
        $posts = Post::with('user:id,name,avatar')->get();

        // Pobieramy zalogowanego użytkownika
        $loggedInUser = Auth::user();

        $postsWithUserInfo = $posts->map(function ($post) use ($loggedInUser) {
            // Dodajemy informacje o użytkowniku, który stworzył post
            $post->author = [
                'name' => $post->user->name,
                'avatar' => $post->user->avatar ? asset('storage/' . $post->user->avatar) : asset('storage/users/avatars/default.png'),
            ];

            // Dodajemy flagę, czy zalogowany użytkownik jest twórcą tego posta
            $post->isCreator = $loggedInUser->id === $post->user_id;

            // Dodatkowe dane o poście
            unset($post->user);  // Usuwamy dane o użytkowniku z głównego obiektu posta, aby nie powtarzać

            return $post;
        });

        return response()->json([
            'posts' => $postsWithUserInfo,
        ]);
    }

    // Inne metody jak store, update, destroy itd.


    public function store(Request $request, $courseId): \Illuminate\Http\JsonResponse
    {
        // Walidacja danych wejściowych
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'file' => 'nullable|mimes:jpeg,png,pdf|max:2048', // Walidacja pliku
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        // Tworzenie nowego posta
        $post = new Post();
        $post->title = $request->input('title');
        $post->description = $request->input('description');
        $post->course_id = $courseId; // Przypisanie course_id z URL
        $post->user_id = Auth::id(); // Ustawienie ID zalogowanego użytkownika
        $post->save();

        // Obsługa pliku (jeśli jest)
        if ($request->hasFile('file')) {
            $filePath = $request->file('file')->store('posts/files', 'public');
            $post->file_path = $filePath;
            $post->save();
        }

        return response()->json([
            'message' => 'Post created successfully',
            'post' => $post
        ], 201);
    }

    public function update(Request $request, $courseId, $id): \Illuminate\Http\JsonResponse
    {
        // Walidacja danych wejściowych
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'file' => 'nullable|mimes:jpeg,png,pdf|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        // Sprawdzenie, czy post istnieje i czy należy do podanego kursu
        $post = Post::where('course_id', $courseId)->find($id);

        if (!$post) {
            return response()->json(['error' => 'Post not found or does not belong to this course'], 404);
        }

        // Sprawdzamy, czy użytkownik ma prawo edytować post
        if ($post->user_id !== Auth::id() && !Auth::user()->is_admin && !Auth::user()->is_moderator) {
            return response()->json(['error' => 'Unauthorized to update this post'], 403);
        }

        // Zaktualizowanie danych posta
        $post->title = $request->input('title');
        $post->description = $request->input('description');

        // Obsługa pliku (jeśli jest)
        if ($request->hasFile('file')) {
            // Usuń stary plik, jeśli istnieje
            if ($post->file_path && Storage::disk('public')->exists($post->file_path)) {
                Storage::disk('public')->delete($post->file_path);
            }

            $filePath = $request->file('file')->store('posts/files', 'public');
            $post->file_path = $filePath;
        }

        // Sprawdzamy, czy coś się zmieniło i zapisujemy
        if ($post->isDirty()) {
            $post->save();
        }

        return response()->json([
            'message' => 'Post updated successfully',
            'post' => $post
        ]);
    }


    public function destroy($courseId, $id): \Illuminate\Http\JsonResponse
    {
        // Sprawdzenie, czy post istnieje i czy należy do podanego kursu
        $post = Post::where('course_id', $courseId)->find($id);

        if (!$post) {
            return response()->json(['error' => 'Post not found or does not belong to this course'], 404);
        }

        // Sprawdzamy, czy użytkownik ma prawo usunąć post
        if ($post->user_id !== Auth::id() && !Auth::user()->is_admin && !Auth::user()->is_moderator) {
            return response()->json(['error' => 'Unauthorized to delete this post'], 403);
        }

        // Jeśli post ma przypisany plik, usuwamy go z dysku
        if ($post->file_path && Storage::disk('public')->exists($post->file_path)) {
            Storage::disk('public')->delete($post->file_path);
        }

        // Usuwamy post
        $post->delete();

        return response()->json([
            'message' => 'Post deleted successfully'
        ]);
    }
}
