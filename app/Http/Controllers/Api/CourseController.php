<?php

namespace App\Http\Controllers\Api;
use App\Models\Course;
use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;



class CourseController extends Controller
{
    // Sprawdzenie uprawnień użytkownika
    private function checkPermissions(Course $course)
    {
        $user = Auth::user();

        // Sprawdzenie, czy użytkownik jest administratorem, właścicielem kursu lub moderatorem
        if ($user->hasRole('admin') || $user->id === $course->user_id || $user->roleInCourse($course) === 'moderator') {
            return true;
        }

        return false;
    }

    // Wyświetlanie wszystkich kursów użytkownika
    public function index()
    {
        $user = Auth::user();

        // Pobieranie kursów użytkownika
        $courses = $user->courses; // To wykorzystuje metodę "courses" zdefiniowaną w modelu User

        // Zwracamy kursy w formacie JSON
        return response()->json($courses);
    }

    // Pobieranie avatara kursu
    public function downloadAvatar($id)
    {
        $course = Course::findOrFail($id);

        if (!$this->checkPermissions($course)) {
            return response()->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$course->avatar) {
            return response()->json(['error' => 'No avatar found for this course'], 404);
        }

        $avatarPath = storage_path("app/public/{$course->avatar}");

        if (!file_exists($avatarPath)) {
            return response()->json(['error' => 'Avatar file not found'], 404);
        }

        return response()->download($avatarPath);
    }
    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        // Walidacja danych wejściowych
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'type' => 'required|in:public,private,100% private',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        // Sprawdzamy, czy użytkownik jest zalogowany
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Tworzymy nowy kurs
        $course = Course::create([
            'title' => $request->title,
            'description' => $request->description,
            'type' => $request->type,
            'user_id' => $user->id, // Twórca kursu to użytkownik, który go tworzy
        ]);

        // Zapisanie avatara, jeśli jest
        if ($request->hasFile('avatar')) {
            // Przechowujemy plik w publicznej przestrzeni, np. /storage/courses/avatars/
            $avatarPath = $request->file('avatar')->store('courses/avatars', 'public');
            $course->avatar = $avatarPath;
            $course->save();
        }

        // Dodajemy twórcę kursu do tabeli course_user jako właściciela
        try {
            // Przypisujemy użytkownika jako właściciela do kursu w tabeli `course_user`
            $course->users()->attach($user->id, ['role' => 'owner', 'status' => 'accepted']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to attach user to course', 'message' => $e->getMessage()], 500);
        }

        return response()->json([
            'message' => 'Course created successfully!',
            'course' => $course,
        ], 201);
    }


    // Aktualizacja kursu
    public function update(Request $request, $id)
    {
        $course = Course::findOrFail($id);

        if (!$this->checkPermissions($course)) {
            return response()->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'type' => 'sometimes|required|in:public,private,100% private',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        if ($validator->fails()) {
            return response ()->json(['error' => $validator->errors()], Response::HTTP_BAD_REQUEST);
        }

        $course->update($request->only('title', 'description', 'type'));

        if ($request->hasFile('avatar')) {
            if ($course->avatar && Storage::exists("public/courses/avatars/{$course->avatar}")) {
                Storage::delete("public/courses/avatars/{$course->avatar}");
            }
            $course->avatar = $request->file('avatar')->store('courses', 'public');
            $course->save();
        }

        return response()->json(['message' => 'Course updated successfully', 'course' => $course]);
    }


    public function removeInvite($courseId, Request $request): \Illuminate\Http\JsonResponse
    {
        // Walidacja danych wejściowych
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',  // Walidacja dla adresu e-mail
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        // Sprawdzamy, czy użytkownik jest zalogowany
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Sprawdzamy, czy kurs istnieje
        $course = Course::find($courseId);
        if (!$course) {
            return response()->json(['error' => 'Course not found'], 404);
        }

        // Sprawdzamy, czy użytkownik o podanym e-mailu istnieje
        $userToRemove = User::where('email', $request->email)->first();
        if (!$userToRemove) {
            return response()->json(['error' => 'User with this email not found'], 404);
        }

        // Sprawdzamy, czy zaproszenie istnieje w kursie i dla tego użytkownika
        $invite = DB::table('course_user')
            ->where('course_id', $courseId)
            ->where('user_id', $userToRemove->id)  // Zamiast emaila używamy user_id
            ->where('status', 'pending')  // Tylko zaproszenia w statusie 'pending'
            ->first();

        // Jeśli zaproszenie nie istnieje, zwrócimy odpowiednią informację
        if (!$invite) {
            return response()->json(['error' => 'Invitation not found or already accepted'], 404);
        }

        // Zmieniamy status zaproszenia tylko tego jednego użytkownika
        DB::table('course_user')
            ->where('course_id', $courseId)
            ->where('user_id', $userToRemove->id)  // Zamiast emaila używamy user_id
            ->where('status', 'pending')  // Tylko zaproszenia w statusie 'pending'
            ->update(['status' => 'cancelled', 'updated_at' => now()]);

        return response()->json(['message' => 'Invitation removed successfully']);
    }


    public function acceptInvite($courseId): \Illuminate\Http\JsonResponse
    {
        // Sprawdzamy, czy użytkownik jest zalogowany
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Sprawdzamy, czy kurs istnieje
        $course = Course::find($courseId);
        if (!$course) {
            return response()->json(['error' => 'Course not found'], 404);
        }

        // Sprawdzamy, czy zaproszenie istnieje dla tego użytkownika
        $invite = DB::table('course_user')
            ->where('course_id', $courseId)
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->first();

        // Jeśli zaproszenie nie istnieje lub zostało już zaakceptowane, zwracamy odpowiedni błąd
        if (!$invite) {
            return response()->json(['error' => 'Invitation not found or already accepted'], 404);
        }

        // Zmieniamy status zaproszenia na 'accepted' tylko dla tego użytkownika
        DB::table('course_user')
            ->where('course_id', $courseId)
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->update(['status' => 'accepted', 'updated_at' => now()]);

        return response()->json(['message' => 'Invitation accepted successfully']);
    }


    public function rejectInvite($courseId): \Illuminate\Http\JsonResponse
    {
        // Sprawdzamy, czy użytkownik jest zalogowany
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Sprawdzamy, czy kurs istnieje
        $course = Course::find($courseId);
        if (!$course) {
            return response()->json(['error' => 'Course not found'], 404);
        }

        // Sprawdzamy, czy zaproszenie istnieje dla tego użytkownika i kursu
        $invite = DB::table('course_user')
            ->where('course_id', $courseId)
            ->where('user_id', $user->id)  // Zaproszenie musi dotyczyć tego użytkownika
            ->where('status', 'pending')  // Sprawdzamy tylko zaproszenia w stanie 'pending'
            ->first();

        // Jeśli zaproszenie nie istnieje, zwrócimy odpowiednią informację
        if (!$invite) {
            return response()->json(['error' => 'Invitation not found or already accepted/rejected'], 404);
        }

        // Zmieniamy status zaproszenia na 'rejected'
        DB::table('course_user')
            ->where('course_id', $courseId)
            ->where('user_id', $user->id)
            ->where('status', 'pending') // Zmieniamy tylko zaproszenie w stanie 'pending'
            ->update(['status' => 'rejected', 'updated_at' => now()]);

        return response()->json(['message' => 'Invitation rejected successfully']);
    }

    public function removeUser($courseId, Request $request): \Illuminate\Http\JsonResponse
    {
        $course = Course::find($courseId);
        if (!$course) {
            return response()->json(false);
        }

        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $authUser = auth()->user();
        $role = $course->users()->where('user_id', $authUser->id)->value('role');

        // Tylko owner/admin/moderator mogą usuwać
        if (!in_array($role, ['owner', 'admin', 'moderator'])) {
            return response()->json(false);
        }

        $userToRemove = User::where('email', $request->email)->first();

        if (!$userToRemove || !$course->users()->where('user_id', $userToRemove->id)->exists()) {
            return response()->json(false);
        }

        // Nie pozwalamy usunąć samego siebie
        if ($userToRemove->id === $authUser->id) {
            return response()->json(false);
        }

        $course->users()->detach($userToRemove->id);

        return response()->json(true);
    }

    // Usuwanie kursu
    public function destroy($id)
    {
        $course = Course::findOrFail($id);

        if (!$this->checkPermissions($course)) {
            return response()->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        if ($course->avatar && Storage::exists("public/courses/{$course->avatar}")) {
            Storage::delete("public/courses/{$course->avatar}");
        }

        $course->delete();

        return response()->json(['message' => 'Course deleted successfully']);
    }
}
