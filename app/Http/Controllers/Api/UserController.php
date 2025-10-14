<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Http\JsonResponse;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;

class UserController extends Controller
{
    // POST /api/login
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email'    => 'required|string|email',
            'password' => 'required|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $credentials = $validator->validated();

        // Guard 'api' = JWT (config/auth.php)
        if (!$token = auth('api')->attempt($credentials)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // W tym request nie ma jeszcze Authorization header – pobierz usera z nowo wydanego tokenu
        $user = auth('api')->setToken($token)->user();

        return response()->json([
            'message'     => 'Login successful',
            'userId'      => $user->id,
            'token'       => $token,
            'token_type'  => 'Bearer',
            'expires_in'  => auth('api')->factory()->getTTL() * 60, // sekundy
            'user'        => $user->only(['id', 'name', 'email']) + ['avatar_url' => $user->avatar_url],
        ]);
    }

    // POST /api/users/register
    public function store(Request $request): JsonResponse
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

        // Dzięki casts ['password' => 'hashed'] hasło zostanie zahashowane automatycznie
        $user->password = (string) $request->input('password');

        // Domyślny avatar
        $user->avatar   = User::DEFAULT_AVATAR_RELATIVE;
        $user->save();

        return response()->json([
            'message' => 'User created successfully!',
            'user'    => $user->only(['id', 'name', 'email']) + ['avatar_url' => $user->avatar_url],
        ], 201);
    }

    // PATCH /api/me/profile
    public function update(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user(); // spójnie z middleware 'auth:api'
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'name'     => 'sometimes|present|string|max:255',
            'email'    => 'sometimes|present|string|email|max:255|unique:users,email,' . $user->id,
            'password' => 'sometimes|present|string|min:8|confirmed',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        if ($request->has('name'))     { $user->name     = (string) $request->input('name'); }
        if ($request->has('email'))    { $user->email    = (string) $request->input('email'); }
        if ($request->has('password')) { $user->password = (string) $request->input('password'); } // cast 'hashed'

        $user->save();
        $user->refresh();

        return response()->json([
            'message' => 'User updated successfully!',
            'user'    => $user->only(['id', 'name', 'email']) + ['avatar_url' => $user->avatar_url],
        ]);
    }

    // POST /api/me/profile/avatar
    public function updateAvatar(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
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

    // GET /api/me/profile/avatar
    public function downloadAvatar(): BinaryFileResponse|JsonResponse
    {
        /** @var User|null $user */
        $user = request()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $relative = $user->avatar ?: User::DEFAULT_AVATAR_RELATIVE;

        if (!Storage::disk('public')->exists($relative)) {
            $relative = User::DEFAULT_AVATAR_RELATIVE;
            if (!Storage::disk('public')->exists($relative)) {
                return response()->json(['error' => 'Avatar not found'], 404);
            }
        }

        $absolute = Storage::disk('public')->path($relative);
        return response()->download($absolute);
    }

    // DELETE /api/me/profile
    public function destroy(): JsonResponse
    {
        /** @var User|null $user */
        $user = request()->user();
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

    // POST /api/me/logout
    public function logout(): JsonResponse
    {
        try {
            auth('api')->logout(); // unieważnij bieżący JWT
        } catch (JWTException $e) {
            // fallback – jeśli guard nie miał tokenu, spróbuj jawnie
            try {
                $token = JWTAuth::getToken();
                if ($token) {
                    JWTAuth::invalidate($token);
                }
            } catch (\Throwable $t) {
                // no-op
            }
        }

        return response()->json(['message' => 'User logged out successfully']);
    }

    // GET /api/me/profile (alias show)
    public function show(): JsonResponse
    {
        /** @var User|null $user */
        $user = request()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return response()->json([
            'user' => $user->only(['id', 'name', 'email']) + ['avatar_url' => $user->avatar_url],
        ]);
    }
}
