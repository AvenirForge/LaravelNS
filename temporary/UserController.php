<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    public function index(): \Illuminate\Http\JsonResponse
    {
        return response()->json(User::all());
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|unique:users',
            'password' => 'required|string|min:6',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        if ($request->hasFile('avatar')) {
            $validatedData['avatar'] = $request->file('avatar')->store('avatars', 'public');
        }
        $validatedData['password'] = Hash::make($validatedData['password']);
        $user = User::create($validatedData);

        $token = Auth::login($user);
        return response()->json(data: [
            'status' => 'success',
            'message' => 'User created successfully',
            'user' => $user,
            'authorization' => [
                'token' => $token,
                'type' => 'Bearer',
            ]
        ]);
    }

    public function login (Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:8',
        ]);
        $credentials = $request->only('email', 'password');
        $token = Auth::attempt(credentials: $credentials);
        if (!$token)
        {
            return response()->json(data: [
                'status' => 'error',
                'message' => 'Unauthorized',
            ], status:401);
        }
        $user = Auth::user();
        return response()->json(data: [
            'status' => 'success',
            'message' => 'User logged successfully',
            'user' => $user,
            'authorization' => [
                'token' => $token,
                'type' => 'Bearer',
            ]
        ]);
    }
    public function logout(): JsonResponse
    {
        Auth::logout();
        return response()->json(data: [
            'status' => 'success',
            'message' => 'User logged out successfully',
        ]);
    }
    public function show($id): \Illuminate\Http\JsonResponse
    {
        $user = User::findOrFail($id);

        if ($user->id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized.'], 403);
        }

        return response()->json($user);
    }

    public function update(Request $request, $id): \Illuminate\Http\JsonResponse
    {
        $user = User::findOrFail($id);

        if ($user->id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized.'], 403);
        }

        $validatedData = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|unique:users,email,'.$id,
            'password' => 'sometimes|string|min:6',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        if ($request->hasFile('avatar')) {
            if ($user->avatar && Storage::exists('public/' . $user->avatar)) {
                Storage::delete('public/' . $user->avatar);
            }

            $validatedData['avatar'] = $request->file('avatar')->store('avatars', 'public');
        }

        if (isset($validatedData['password'])) {
            $validatedData['password'] = Hash::make($validatedData['password']);
        }

        $user->update($validatedData);

        return response()->json($user);
    }
    public function destroy($id): \Illuminate\Http\JsonResponse
    {
        $user = User::findOrFail($id);

        if ($user->id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized.'], 403);
        }

        if ($user->avatar && Storage::exists('public/' . $user->avatar)) {
            Storage::delete('public/' . $user->avatar);
        }

        $user->delete();
        return response()->json(data: [
            'status' => 'success',
            'message' => 'User successfully deleted',
        ]);
    }
}
