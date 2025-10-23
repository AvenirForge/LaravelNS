<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Note;
use App\Models\Test;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as Http;

class CourseController extends Controller
{
    // ===== Helpery auth (zawsze guard 'api') =====
    private function me(): ?\Illuminate\Contracts\Auth\Authenticatable
    {
        return Auth::guard('api')->user();
    }
    private function meId(): ?int
    {
        $u = $this->me();
        return $u ? (int)$u->id : null;
    }

    // ===== Normalizacja =====
    private function canonicalEmail(string $email): string
    {
        $email = trim(mb_strtolower($email));
        if (function_exists('idn_to_ascii') && str_contains($email,'@')) {
            [$local,$domain] = explode('@',$email,2);
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii) $email = $local.'@'.$ascii;
        }
        return $email;
    }

    // ===== Uprawnienia kursu =====
    private function roleInCourse(Course $course, int $userId): string
    {
        if ((int)$userId === (int)$course->user_id) return 'owner';

        $pivot = DB::table('courses_users')
            ->where('course_id', $course->id)
            ->where('user_id', $userId)
            ->first(); // Pobierz cały wiersz

        // Jeśli użytkownik nie jest w ogóle powiązany z kursem
        if (!$pivot) {
            // Zwróć rolę, która na pewno nie ma uprawnień
            return 'guest'; // lub 'none'
        }

        $role = $pivot->role;

        // Jeśli jest w kursie, ale rola to NULL lub 'user', traktuj jak 'member'
        if (!$role || $role === 'user') {
            return 'member';
        }

        return $role;
    }
    private function checkPermissions(Course $course): bool
    {
        $me = $this->me();
        if (!$me) return false;
        $role = $this->roleInCourse($course, (int)$me->id);
        return in_array($role, ['owner','admin','moderator'], true);
    }

    private function canModerateUser(Course $course, int $actorId, int $targetId): bool
    {
        $actorRole  = $this->roleInCourse($course, $actorId);
        $targetRole = $this->roleInCourse($course, $targetId);

        if ($actorRole === 'owner') return true;
        if ($actorRole === 'admin') return in_array($targetRole, ['moderator','member'], true);
        if ($actorRole === 'moderator') return $targetRole === 'member';
        return false;
    }

    // ===== CRUD kursów (Twoja logika, tylko guard) =====
    public function index()
    {
        $user = $this->me();
        if (!$user) return response()->json(['error'=>'Unauthorized'], 401);

        $ownerIds = Course::where('user_id', $user->id)->pluck('id')->all();
        $pivotIds = DB::table('courses_users')->where('user_id', $user->id)->pluck('course_id')->all();
        $ids = array_values(array_unique(array_merge($ownerIds, $pivotIds)));

        $courses = empty($ids) ? [] : Course::whereIn('id', $ids)->get();
        return response()->json($courses);
    }

    public function downloadAvatar($id)
    {
        $course = Course::findOrFail($id);
        if (!$this->checkPermissions($course)) return response()->json(['error'=>'Forbidden'], 403);

        if (!$course->avatar) return response()->json(['error'=>'No avatar found for this course'], 404);
        if (!Storage::disk('public')->exists($course->avatar)) return response()->json(['error'=>'Avatar file not found'], 404);

        $absolute = Storage::disk('public')->path($course->avatar);
        $mime = Storage::disk('public')->mimeType($course->avatar) ?? 'image/jpeg';
        return Response::file($absolute, ['Content-Type'=>$mime]);
    }

    public function store(Request $request)
    {
        $v = Validator::make($request->all(), [
            'title'       => 'required|string|max:255',
            'description' => 'required|string',
            'type'        => 'required|in:public,private,100% private',
            'avatar'      => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);
        if ($v->fails()) return response()->json(['error'=>$v->errors()], 400);

        $user = $this->me();
        if (!$user) return response()->json(['error'=>'Unauthorized'], 401);

        $course = Course::create([
            'title'       => (string)$request->input('title'),
            'description' => (string)$request->input('description'),
            'type'        => (string)$request->input('type'),
            'user_id'     => $user->id,
        ]);

        if ($request->hasFile('avatar')) {
            $course->avatar = $request->file('avatar')->store('courses/avatars','public');
            $course->save();
        }

        DB::table('courses_users')->updateOrInsert(
            ['course_id'=>$course->id, 'user_id'=>$user->id],
            ['role'=>'owner','status'=>'accepted','created_at'=>now(),'updated_at'=>now()]
        );

        return response()->json(['message'=>'Course created successfully!','course'=>$course], 201);
    }

    public function update(Request $request, $id)
    {
        $course = Course::findOrFail($id);
        if (!$this->checkPermissions($course)) return response()->json(['error'=>'Forbidden'], 403);

        $v = Validator::make($request->all(), [
            'title'       => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'type'        => 'sometimes|required|in:public,private,100% private',
            'avatar'      => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);
        if ($v->fails()) return response()->json(['error'=>$v->errors()], 400);

        $course->update($request->only('title','description','type'));
        if ($request->hasFile('avatar')) {
            if ($course->avatar && Storage::disk('public')->exists($course->avatar)) {
                Storage::disk('public')->delete($course->avatar);
            }
            $course->avatar = $request->file('avatar')->store('courses/avatars','public');
            $course->save();
        }
        return response()->json(['message'=>'Course updated successfully','course'=>$course]);
    }

    public function destroy($id)
    {
        $course = Course::findOrFail($id);
        if (!$this->checkPermissions($course)) return response()->json(['error'=>'Forbidden'], 403);

        if ($course->avatar && Storage::disk('public')->exists($course->avatar)) {
            Storage::disk('public')->delete($course->avatar);
        }
        $course->delete();
        return response()->json(['message'=>'Course deleted successfully']);
    }

    // ===== Moderacja: kick + purge =====
    public function removeUser(Request $request, $courseId)
    {
        $course = Course::findOrFail($courseId);
        if (!$this->checkPermissions($course)) return response()->json(['error'=>'Forbidden'], 403);

        $data = $request->validate(['email'=>'required|email']);
        $emailNorm = $this->canonicalEmail($data['email']);
        $user = User::whereRaw('LOWER(TRIM(email)) = ?', [$emailNorm])->first();
        if (!$user) return response()->json(['error'=>'User not found'], 404);
        if ((int)$user->id === (int)$course->user_id) return response()->json(['error'=>'Cannot remove course owner'], 422);

        $actorId = $this->meId() ?? 0;
        if (!$this->canModerateUser($course, $actorId, (int)$user->id)) {
            return response()->json(['error'=>'Insufficient permissions for this target user'], 403);
        }

        $isMember = DB::table('courses_users')->where('course_id',$course->id)->where('user_id',$user->id)->exists();
        if (!$isMember) return response()->json(['error'=>'User is not a course member'], 404);

        DB::transaction(function () use ($course, $user) {
            DB::table('courses_users')->where('course_id',$course->id)->where('user_id',$user->id)->delete();

            Note::where('course_id',$course->id)->where('user_id',$user->id)
                ->update(['is_private'=>true,'course_id'=>null]);

            if (class_exists(\App\Models\Test::class)) {
                Test::where('course_id',$course->id)->where('user_id',$user->id)
                    ->update(['course_id'=>null]);
            }
        });

        return response()->json(true);
    }

    public function purgeUserNotesInCourse(Request $request, int $courseId, int $userId)
    {
        $course = Course::findOrFail($courseId);
        if (!$this->checkPermissions($course)) return response()->json(['error'=>'Forbidden'], 403);

        $actorId = $this->meId() ?? 0;
        if (!$this->canModerateUser($course, $actorId, $userId)) {
            return response()->json(['error'=>'Insufficient permissions for this target user'], 403);
        }

        $affected = Note::where('course_id',$course->id)->where('user_id',$userId)
            ->update(['is_private'=>true,'course_id'=>null]);

        return response()->json([
            'message'=>'User notes unshared from course',
            'courseId'=>$course->id,
            'userId'=>$userId,
            'affected'=>$affected,
        ]);
    }

    public function purgeUserTestsInCourse(Request $request, int $courseId, int $userId)
    {
        $course = Course::findOrFail($courseId);
        if (!$this->checkPermissions($course)) return response()->json(['error'=>'Forbidden'], 403);

        $actorId = $this->meId() ?? 0;
        if (!$this->canModerateUser($course, $actorId, $userId)) {
            return response()->json(['error'=>'Insufficient permissions for this target user'], 403);
        }

        $affected = 0;
        if (class_exists(\App\Models\Test::class)) {
            $affected = Test::where('course_id',$course->id)->where('user_id',$userId)
                ->update(['course_id'=>null]);
        }

        return response()->json([
            'message'=>'User tests unshared from course',
            'courseId'=>$course->id,
            'userId'=>$userId,
            'affected'=>$affected,
        ]);
    }

    public function unshareNoteAdmin(Request $request, int $courseId, int $noteId)
    {
        $course = Course::findOrFail($courseId);
        if (!$this->checkPermissions($course)) return response()->json(['error'=>'Forbidden'], 403);

        $note = Note::findOrFail($noteId);
        if ((int)$note->course_id !== (int)$course->id) {
            return response()->json([
                'error'=>'Note is not shared with this course',
                'note_id'=>$note->id,
                'course_id_passed'=>$course->id,
                'course_id_actual'=>$note->course_id,
            ], 409);
        }

        $actorId = $this->meId() ?? 0;
        $targetId = (int)$note->user_id;
        if (!$this->canModerateUser($course, $actorId, $targetId)) {
            return response()->json(['error'=>'Insufficient permissions for this target user'], 403);
        }

        $note->is_private = true;
        $note->course_id = null;
        $note->save();

        return response()->json(['message'=>'Note unshared from course','note'=>$note]);
    }

    public function unshareTestAdmin(Request $request, int $courseId, int $testId)
    {
        $course = Course::findOrFail($courseId);
        if (!$this->checkPermissions($course)) return response()->json(['error'=>'Forbidden'], 403);

        if (!class_exists(\App\Models\Test::class)) {
            return response()->json(['message'=>'Test model not available — skipped','test'=>null], 200);
        }

        $test = Test::findOrFail($testId);
        if ((int)$test->course_id !== (int)$course->id) {
            return response()->json([
                'error'=>'Test is not shared with this course',
                'test_id'=>$test->id,
                'course_id_passed'=>$course->id,
                'course_id_actual'=>$test->course_id,
            ], 409);
        }

        $actorId = $this->meId() ?? 0;
        $targetId = (int)$test->user_id;
        if (!$this->canModerateUser($course, $actorId, $targetId)) {
            return response()->json(['error'=>'Insufficient permissions for this target user'], 403);
        }

        $test->course_id = null;
        $test->save();

        return response()->json(['message'=>'Test unshared from course','test'=>$test]);
    }

    public function setUserRole(Request $request, int $courseId, int $userId)
    {
        $course = Course::findOrFail($courseId);
        if (!$this->checkPermissions($course)) return response()->json(['error'=>'Forbidden'], 403);

        $data = $request->validate(['role'=>['required', Rule::in(['admin','moderator','member','user'])]]);
        $newRole = $data['role'] === 'user' ? 'member' : $data['role'];

        if ((int)$userId === (int)$course->user_id) {
            return response()->json(['error'=>'Cannot change role of course owner'], 422);
        }

        $exists = DB::table('courses_users')->where('course_id',$course->id)->where('user_id',$userId)->exists();
        if (!$exists) return response()->json(['error'=>'User is not a course member'], 404);

        $actorId    = $this->meId() ?? 0;
        $actorRole  = $this->roleInCourse($course, $actorId);
        $targetRole = $this->roleInCourse($course, $userId);

        if (!$this->canModerateUser($course, $actorId, $userId)) {
            return response()->json(['error'=>'Insufficient permissions for this target user'], 403);
        }

        $allowedByActor = match ($actorRole) {
            'owner' => ['admin','moderator','member'],
            'admin' => ['moderator','member'],
            default => [],
        };
        if (!in_array($newRole, $allowedByActor, true)) {
            return response()->json(['error'=>'Role not allowed for this actor'], 403);
        }

        if ($actorRole === 'admin' && $targetRole === 'admin') {
            return response()->json(['error'=>'Admin cannot change another admin'], 403);
        }

        if ($targetRole === $newRole) {
            return response()->json(['message'=>'Role unchanged','user'=>['id'=>(int)$userId,'role'=>$targetRole]]);
        }

        DB::table('courses_users')->where('course_id',$course->id)->where('user_id',$userId)
            ->update(['role'=>$newRole, 'updated_at'=>now()]);

        return response()->json(['message'=>'Role updated','user'=>['id'=>(int)$userId,'role'=>$newRole]]);
    }

    public function setUserRoleByEmail(Request $request, int $courseId)
    {
        $course = Course::findOrFail($courseId);
        if (!$this->checkPermissions($course)) return response()->json(['error'=>'Forbidden'], 403);

        $data = $request->validate([
            'email'=>'required|email',
            'role' => ['required', Rule::in(['admin','moderator','member','user'])],
        ]);
        $newRole = $data['role'] === 'user' ? 'member' : $data['role'];

        $emailNorm = $this->canonicalEmail($data['email']);
        $user = User::whereRaw('LOWER(TRIM(email)) = ?', [$emailNorm])->first();
        if (!$user) return response()->json(['error'=>'User not found'], 404);

        $req = new Request(['role'=>$newRole]);
        return $this->setUserRole($req, $courseId, (int)$user->id);
    }
}
