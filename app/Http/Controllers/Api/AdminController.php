<?php

namespace App\Http\Controllers\Api;

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

    public function showNote($noteId): \Illuminate\Http\JsonResponse
    {
        $user = auth()->user();
        if (!$user || !in_array($user->email, $this->allowedEmails)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $note = Note::find($noteId);

        return $note
            ? response()->json($note)
            : response()->json(['error' => 'Note not found'], 404);
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
}
