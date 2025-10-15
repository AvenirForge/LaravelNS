<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as Http;

class CourseController extends Controller
{
    private function checkPermissions(Course $course): bool
    {
        $user = Auth::user();
        if (!$user) return false;

        if ((int)$user->id === (int)$course->user_id) {
            return true; // właściciel kursu
        }

        $pivot = $course->users()->where('user_id', $user->id)->first();
        $role  = $pivot?->pivot?->role;

        return in_array($role, ['owner', 'admin', 'moderator'], true);
    }

    /** GET /api/me/courses */
    public function index()
    {
        $user = Auth::user();

        // Stabilnie: kursy gdzie user jest właścicielem LUB jest w pivocie
        $ownerIds = Course::where('user_id', $user->id)->pluck('id')->all();
        $pivotIds = DB::table('courses_users')->where('user_id', $user->id)->pluck('course_id')->all();
        $ids = array_values(array_unique(array_merge($ownerIds, $pivotIds)));

        $courses = empty($ids) ? [] : Course::whereIn('id', $ids)->get();

        return response()->json($courses);
    }

    /** GET /api/me/courses/{id}/avatar */
    public function downloadAvatar($id)
    {
        $course = Course::findOrFail($id);

        if (!$this->checkPermissions($course)) {
            return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);
        }

        if (!$course->avatar) {
            return response()->json(['error' => 'No avatar found for this course'], 404);
        }

        $path = $course->avatar;
        if (!Storage::disk('public')->exists($path)) {
            return response()->json(['error' => 'Avatar file not found'], 404);
        }

        $absolute = Storage::disk('public')->path($path);
        $mime = Storage::disk('public')->mimeType($path) ?? 'image/jpeg';

        return Response::file($absolute, ['Content-Type' => $mime]);
    }

    /** POST /api/me/courses */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title'       => 'required|string|max:255',
            'description' => 'required|string',
            'type'        => 'required|in:public,private,100% private',
            'avatar'      => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $course = Course::create([
            'title'       => (string) $request->input('title'),
            'description' => (string) $request->input('description'),
            'type'        => (string) $request->input('type'),
            'user_id'     => $user->id,
        ]);

        if ($request->hasFile('avatar')) {
            $avatarPath = $request->file('avatar')->store('courses/avatars', 'public');
            $course->avatar = $avatarPath;
            $course->save();
        }

        // twórca → owner w pivocie
        $course->users()->attach($user->id, ['role' => 'owner', 'status' => 'accepted']);

        return response()->json([
            'message' => 'Course created successfully!',
            'course'  => $course,
        ], 201);
    }

    /** PUT /api/me/courses/{id} */
    public function update(Request $request, $id)
    {
        $course = Course::findOrFail($id);

        if (!$this->checkPermissions($course)) {
            return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);
        }

        $validator = Validator::make($request->all(), [
            'title'       => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'type'        => 'sometimes|required|in:public,private,100% private',
            'avatar'      => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], Http::HTTP_BAD_REQUEST);
        }

        $course->update($request->only('title', 'description', 'type'));

        if ($request->hasFile('avatar')) {
            if ($course->avatar && Storage::disk('public')->exists($course->avatar)) {
                Storage::disk('public')->delete($course->avatar);
            }
            $course->avatar = $request->file('avatar')->store('courses/avatars', 'public');
            $course->save();
        }

        return response()->json(['message' => 'Course updated successfully', 'course' => $course]);
    }

    /** DELETE /api/me/courses/{id} */
    public function destroy($id)
    {
        $course = Course::findOrFail($id);

        if (!$this->checkPermissions($course)) {
            return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);
        }

        if ($course->avatar && Storage::disk('public')->exists($course->avatar)) {
            Storage::disk('public')->delete($course->avatar);
        }

        $course->delete();

        return response()->json(['message' => 'Course deleted successfully']);
    }

    /** POST /api/courses/{courseId}/remove-user  (body: { "email": "..." }) */
    public function removeUser(Request $request, $courseId)
    {
        $course = Course::findOrFail($courseId);

        if (!$this->checkPermissions($course)) {
            return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);
        }

        $data = $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $data['email'])->first();
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        // nie pozwalamy wyrzucić ownera (creatora)
        if ((int)$user->id === (int)$course->user_id) {
            return response()->json(['error' => 'Cannot remove course owner'], 422);
        }

        $course->users()->detach($user->id);

        return response()->json(true);
    }

}
