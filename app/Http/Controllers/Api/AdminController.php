<?php

namespace App\Http\Controllers\Api;

use App\Models\Course;
use App\Models\User;
use App\Models\Note;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class AdminController extends Controller
{
    protected array $allowedEmails = ['krzysztofweimann@icloud.com', 'mkordys98@gmail.com'];

    public function allUsers(): \Illuminate\Http\JsonResponse
    {
        $user = auth()->user();
        if (!$user || !in_array($user->email, $this->allowedEmails)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return response()->json(User::all());
    }

    public function showUser($id): \Illuminate\Http\JsonResponse
    {
        $user = auth()->user();
        if (!$user || !in_array($user->email, $this->allowedEmails)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $user = User::find($id);

        return $user
            ? response()->json($user)
            : response()->json(['error' => 'User not found'], 404);
    }

    public function updateUser(Request $request, $id): \Illuminate\Http\JsonResponse
    {
        $user = auth()->user();
        if (!$user || !in_array($user->email, $this->allowedEmails)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $user = User::find($id);
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|string|email|max:255|unique:users,email,' . $id,
            'password' => 'nullable|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $user->update($request->only(['name', 'email', 'password']));

        return response()->json([
            'message' => 'User updated successfully!',
            'user' => $user,
        ]);
    }

    public function deleteUser($id): \Illuminate\Http\JsonResponse
    {
        $user = auth()->user();
        if (!$user || !in_array($user->email, $this->allowedEmails)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $user = User::find($id);
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        if ($user->avatar) {
            Storage::delete($user->avatar);
        }

        Note::where('user_id', $user->id)->delete();
        $user->delete();

        return response()->json(['message' => 'User and related notes deleted successfully']);
    }

    public function allNotes(): \Illuminate\Http\JsonResponse
    {
        $user = auth()->user();
        if (!$user || !in_array($user->email, $this->allowedEmails)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return response()->json(Note::all());
    }

    public function downloadNote($noteId): \Illuminate\Http\Response
    {
        $user = auth()->user();

        // Sprawdzenie, czy użytkownik jest autoryzowany
        if (!$user || !in_array($user->email, $this->allowedEmails)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Znalezienie notatki po ID
        $note = Note::find($noteId);

        // Sprawdzenie, czy notatka istnieje
        if (!$note) {
            return response()->json(['error' => 'Note not found'], 404);
        }

        // Ścieżka do pliku notatki
        $filePath = storage_path("app/{$note->file_path}");

        // Sprawdzenie, czy plik istnieje
        if (!file_exists($filePath)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        // Zwrócenie pliku do pobrania
        return response()->download($filePath);
    }

    public function deleteNote($noteId): \Illuminate\Http\JsonResponse
    {
        $user = auth()->user();
        if (!$user || !in_array($user->email, $this->allowedEmails)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $note = Note::find($noteId);

        if (!$note) {
            return response()->json(['error' => 'Note not found'], 404);
        }

        if ($note->file_path) {
            Storage::delete($note->file_path);
        }

        $note->delete();

        return response()->json(['message' => 'Note deleted successfully']);
    }

    public function updateNote(Request $request, $id): \Illuminate\Http\JsonResponse
    {
        $user = auth()->user();
        if (!$user || !in_array($user->email, $this->allowedEmails)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $note = Note::find($id);
        if (!$note) {
            return response()->json(['error' => 'Note not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:5000',
            'visibility' => 'nullable|in:public,private',
            'file' => 'nullable|file|mimes:pdf,xlsx,jpg,jpeg,png',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        if ($request->has('title')) {
            $note->title = $request->title;
        }

        if ($request->has('description')) {
            $note->description = $request->description;
        }

        if ($request->has('visibility')) {
            $note->visibility = $request->visibility;
        }

        if ($request->hasFile('file')) {
            $filePath = $request->file('file')->store('notes_files');
            $note->file_path = $filePath;
        }

        $note->save();

        return response()->json([
            'message' => 'Note updated successfully!',
            'note' => $note,
        ]);
    }

    public function allCourses()
    {
        return response()->json(Course::all());
    }

    public function showUsersForCourse($courseId): \Illuminate\Http\JsonResponse
    {
        $course = Course::find($courseId);

        if (!$course) {
            return response()->json(['error' => 'Course not found'], 404);
        }

        $users = $course->users;
        return response()->json(['users' => $users]);
    }
    public function addUserToCourse(Request $request, $courseId): \Illuminate\Http\JsonResponse
    {
        $course = Course::findOrFail($courseId);

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'role' => 'required|in:owner,moderator,member',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $course->users()->attach($request->user_id, ['role' => $request->role]);

        return response()->json(['message' => 'User added to course']);
    }
    public function removeUserFromCourse(Request $request, $courseId): \Illuminate\Http\JsonResponse
    {
        $course = Course::findOrFail($courseId);

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $course->users()->detach($request->user_id);

        return response()->json(['message' => 'User removed from course']);
    }
    public function updateCourse(Request $request, $courseId): \Illuminate\Http\JsonResponse
    {
        $course = Course::findOrFail($courseId);

        // Weryfikacja uprawnień administratora (domyślnie zakłada się, że admin ma dostęp do edycji)
        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'type' => 'sometimes|required|in:public,private,100% private',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $course->update($request->only('title', 'description', 'type'));

        if ($request->hasFile('avatar')) {
            if ($course->avatar && Storage::exists("public/courses/{$course->avatar}")) {
                Storage::delete("public/courses/{$course->avatar}");
            }
            $course->avatar = $request->file('avatar')->store('courses', 'public');
            $course->save();
        }

        return response()->json(['message' => 'Course updated successfully', 'course' => $course]);
    }

    // Usuwanie kursu
    public function deleteCourse($courseId): \Illuminate\Http\JsonResponse
    {
        $course = Course::findOrFail($courseId);

        // Weryfikacja uprawnień administratora
        if ($course->avatar && Storage::exists("public/courses/{$course->avatar}")) {
            Storage::delete("public/courses/{$course->avatar}");
        }

        $course->delete();

        return response()->json(['message' => 'Course deleted successfully']);
    }
}
