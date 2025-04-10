<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    /**
     * User registration
     */
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

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        if ($request->hasFile('avatar')) {
            $user->changeAvatar($request->file('avatar'));
        }

        $token = JWTAuth::fromUser($user);

        return response()->json([
            'message' => 'User created successfully!',
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    /**
     * User login
     */
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

    /**
     * Show authenticated user's profile
 *
* public function show(): \Illuminate\Http\JsonResponse
    * {
        * return response()->json(Auth::user());
    * }
     * /
     * /**
     * Update authenticated user's profile
     */
    public function update(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|string|email|max:255|unique:users,email,' . $user->id,
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $user->update($request->only('name', 'email'));

        if ($request->hasFile('avatar')) {
            $user->changeAvatar($request->file('avatar'));
        }

        return response()->json(['message' => 'User updated successfully!', 'user' => $user]);
    }

    /**
     * Delete authenticated user's account
     */
    public function destroy(): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();

        if ($user->avatar) {
            Storage::delete($user->avatar);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }

    /**
     * Logout user
     */
    public function logout(): \Illuminate\Http\JsonResponse
    {
        Auth::logout();

        return response()->json(['message' => 'User logged out successfully']);
    }

}
