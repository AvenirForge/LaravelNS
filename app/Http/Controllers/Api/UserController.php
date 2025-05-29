<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    public function login(Request $request): \Illuminate\Http\JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $credentials = $request->only('email', 'password');

        if ($token = JWTAuth::attempt($credentials)) {
            $user = Auth::user();

            return response()->json([
                'message' => 'Login successful',
                'userId' => $user->id,
                'token' => $token,
            ]);
        }

        return response()->json(['error' => 'Unauthorized'], 401);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $user = new User();
        $user->name = $request->input('name');
        $user->email = $request->input('email');
        $user->password = Hash::make($request->input('password'));

        if ($request->hasFile('avatar')) {
            $avatarPath = $request->file('avatar')->store('users/avatars', 'public');
            $user->avatar = $avatarPath;
        } else {
            $user->avatar = 'NONE';
        }

        $user->save();

        return response()->json([
            'message' => 'User created successfully!',
            'user' => $user,
        ], 201);
    }

    public function update(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
            'password' => 'sometimes|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        if ($request->has('name')) {
            $user->name = $request->input('name');
        }

        if ($request->has('email')) {
            $user->email = $request->input('email');
        }

        if ($request->has('password')) {
            $user->password = Hash::make($request->input('password'));
        }

        $user->save();

        return response()->json([
            'message' => 'User updated successfully!',
            'user' => $user,
        ]);
    }

    public function updateAvatar(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Sprawdzenie czy plik istnieje
        if (!$request->hasFile('avatar')) {
            return response()->json(['error' => ['avatar' => ['The avatar file is required.']]], 400);
        }

        $validator = Validator::make($request->all(), [
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        // Usunięcie starego avatara, jeśli istnieje
        if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
            Storage::disk('public')->delete($user->avatar);
        }

        // Zapis nowego avatara
        $avatarPath = $request->file('avatar')->store('users/avatars', 'public');
        $user->avatar = $avatarPath;
        $user->save();

        return response()->json([
            'message' => 'Avatar updated successfully!',
            'avatar_url' => Storage::url($avatarPath),
            'user' => $user,
        ]);
    }
    public function downloadAvatar(): \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\JsonResponse
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $avatarPath = $user->avatar
            ? storage_path("app/public/{$user->avatar}")
            : storage_path("app/users/avatars/default.png");

        if (!file_exists($avatarPath)) {
            return response()->json(['error' => 'Avatar not found'], 404);
        }

        return response()->download($avatarPath);
    }

    public function destroy(): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();

        if ($user->avatar) {
            Storage::delete($user->avatar);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }

    public function logout(): \Illuminate\Http\JsonResponse
    {
        Auth::logout();
        return response()->json(['message' => 'User logged out successfully']);
    }

    /**
     * ✅ NEW: Get current authenticated user profile
     */
    public function show(): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return response()->json([
            'user' => $user,
        ]);
    }
}
