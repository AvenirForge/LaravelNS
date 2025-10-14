<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class UserController extends Controller
{
    public function login(Request $request): \Illuminate\Http\JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email'    => 'required|string|email',
            'password' => 'required|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $credentials = $request->only('email', 'password');
        if (!$token = JWTAuth::attempt($credentials)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        /** @var User $user */
        $user = Auth::user();

        return response()->json([
            'message' => 'Login successful',
            'userId'  => $user->id,
            'token'   => $token,
            'user'    => $user->only(['id', 'name', 'email']) + ['avatar_url' => $user->avatar_url],
        ]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'                  => 'required|string|max:255',
            'email'                 => 'required|string|email|max:255|unique:users,email',
            'password'              => 'required|string|min:8|confirmed',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $user = new User();
        $user->name     = (string) $request->input('name');
        $user->email    = (string) $request->input('email');

        // Dzięki $casts['password' => 'hashed'] możemy przypisać „gołe” hasło – Eloquent je zhashuje
        $user->password = (string) $request->input('password');

        // Ustawiamy avatar domyślny (stała zdefiniowana w modelu User)
        $user->avatar   = User::DEFAULT_AVATAR_RELATIVE;

        $user->save();

        return response()->json([
            'message' => 'User created successfully!',
            'user'    => $user->only(['id', 'name', 'email']) + ['avatar_url' => $user->avatar_url],
        ], 201);
    }

    /**
     * PATCH /api/me/profile
     */
    public function update(Request $request): \Illuminate\Http\JsonResponse
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'name'     => 'sometimes|present|string|max:255',
            'email'    => 'sometimes|present|string|email|max:255|unique:users,email,' . $user->id,
            'password' => 'sometimes|present|string|min:8|confirmed',
            // avatar edytujemy wyłącznie przez /me/profile/avatar
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        if ($request->has('name')) {
            $user->name = (string) $request->input('name');
        }
        if ($request->has('email')) {
            $user->email = (string) $request->input('email');
        }
        if ($request->has('password')) {
            // cast 'hashed' zrobi resztę
            $user->password = (string) $request->input('password');
        }

        $user->save();
        $user->refresh();

        return response()->json([
            'message' => 'User updated successfully!',
            'user'    => $user->only(['id', 'name', 'email']) + ['avatar_url' => $user->avatar_url],
        ]);
    }

    public function updateAvatar(Request $request): \Illuminate\Http\JsonResponse
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (!$request->hasFile('avatar')) {
            return response()->json(['error' => ['avatar' => ['The avatar file is required.']]], 400);
        }

        $validator = Validator::make($request->all(), [
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        if ($user->avatar && $user->avatar !== User::DEFAULT_AVATAR_RELATIVE) {
            if (Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }
        }

        $path = $request->file('avatar')->store('users/avatars', 'public');
        $user->avatar = $path;
        $user->save();
        $user->refresh();

        return response()->json([
            'message'    => 'Avatar updated successfully!',
            'avatar_url' => $user->avatar_url,
            'user'       => $user->only(['id', 'name', 'email']) + ['avatar_url' => $user->avatar_url],
        ]);
    }

    public function downloadAvatar(): BinaryFileResponse|\Illuminate\Http\JsonResponse
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $relative = $user->avatar ?: User::DEFAULT_AVATAR_RELATIVE;

        // Jeżeli brak pliku użytkownika – próbujemy default
        if (!Storage::disk('public')->exists($relative)) {
            $relative = User::DEFAULT_AVATAR_RELATIVE;
            if (!Storage::disk('public')->exists($relative)) {
                return response()->json(['error' => 'Avatar not found'], 404);
            }
        }

        $absolute = Storage::disk('public')->path($relative);
        return response()->download($absolute);
    }

    public function destroy(): \Illuminate\Http\JsonResponse
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if ($user->avatar && $user->avatar !== User::DEFAULT_AVATAR_RELATIVE) {
            if (Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }
        }

        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }

    public function logout(): \Illuminate\Http\JsonResponse
    {
        try {
            $token = JWTAuth::getToken();
            if ($token) {
                JWTAuth::invalidate($token);
            }
        } catch (\Throwable $e) {
            // no-op
        }

        Auth::logout();

        return response()->json(['message' => 'User logged out successfully']);
    }

    public function show(): \Illuminate\Http\JsonResponse
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return response()->json([
            'user' => $user->only(['id', 'name', 'email']) + ['avatar_url' => $user->avatar_url],
        ]);
    }
}
