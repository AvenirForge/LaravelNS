<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as Http;

class InvitationController extends Controller
{
    private function me()
    {
        return Auth::guard('api')->user();
    }

    private function canonicalEmail(string $email): string
    {
        $email = trim(mb_strtolower($email));
        if (function_exists('idn_to_ascii') && str_contains($email, '@')) {
            [$local, $domain] = explode('@', $email, 2);
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii) $email = $local.'@'.$ascii;
        }
        return $email;
    }

    // Wklej tę funkcję pomocniczą (pobraną z CourseController)
    private function roleInCourse(Course $course, int $userId): string
    {
        if ((int)$userId === (int)$course->user_id) return 'owner';

        $pivot = DB::table('courses_users')
            ->where('course_id', $course->id)
            ->where('user_id', $userId)
            ->first();

        if (!$pivot) {
            return 'guest';
        }

        $role = $pivot->role;

        if (!$role || $role === 'user') {
            return 'member';
        }

        return $role;
    }

// ORAZ zastąp istniejącą funkcję canManage tą wersją:
    private function canManage(Course $course): bool
    {
        $me = $this->me();
        if (!$me) return false;

        if ((int)$me->id === (int)$course->user_id) return true;

        // Użyj spójnej logiki do sprawdzania ról
        $role = $this->roleInCourse($course, (int)$me->id);

        return in_array($role, ['owner','admin','moderator'], true);
    }

    public function inviteUser(Request $request, $courseId)
    {
        $course = Course::findOrFail($courseId);
        if (!$this->canManage($course)) {
            return response()->json(['error'=>'Unauthorized'], Http::HTTP_UNAUTHORIZED);
        }

        $data = $request->validate([
            'email' => 'required|email',
            'role'  => 'sometimes|in:owner,admin,moderator,user,member',
        ]);

        $emailRaw  = $data['email'];
        $emailNorm = $this->canonicalEmail($emailRaw);

        $existingUser = User::whereRaw('LOWER(TRIM(email)) = ?', [$emailNorm])->first();
        $existingUserId = $existingUser?->id;

        return DB::transaction(function () use ($course, $data, $emailRaw, $emailNorm, $existingUserId) {
            $rejectedCount = Invitation::where('course_id', $course->id)
                ->where(function ($q) use ($emailNorm, $existingUserId) {
                    $q->whereRaw('LOWER(TRIM(invited_email)) = ?', [$emailNorm]);
                    if ($existingUserId) $q->orWhere('user_id', $existingUserId);
                })
                ->where('status', 'rejected')
                ->lockForUpdate()
                ->count();

            if ($rejectedCount >= 3) {
                return response()->json(['error' => 'Too many rejections for this email. Further invites are blocked.'], 422);
            }

            $role = $data['role'] ?? 'member';
            if ($role === 'user') $role = 'member';

            $invite = Invitation::create([
                'course_id'     => $course->id,
                'inviter_id'    => Auth::guard('api')->id(),
                'user_id'       => $existingUserId,
                'invited_email' => $emailRaw,
                'status'        => 'pending',
                'role'          => $role,
                'token'         => Str::random(48),
                'expires_at'    => now()->addDays(14),
            ]);

            return response()->json([
                'message' => 'Invitation created',
                'invite'  => [
                    'id'     => $invite->id,
                    'token'  => $invite->token,
                    'email'  => $invite->invited_email,
                    'status' => $invite->status,
                    'role'   => $invite->role,
                ],
            ], 200);
        });
    }

    public function invitationsReceived()
    {
        $me = $this->me();

        // KLUCZ: dopasowanie po kanonicznym e-mailu
        $meNorm = $this->canonicalEmail($me->email);

        $list = Invitation::whereRaw('LOWER(TRIM(invited_email)) = ?', [$meNorm])
            ->latest()
            ->get();

        return response()->json([
            'invitations' => $list->map(fn($i) => [
                'course_id' => $i->course_id,
                'token'     => $i->token,
                'status'    => $i->status,
                'role'      => $i->role,
            ])->values(),
        ]);
    }

    public function invitationsSent()
    {
        $me = $this->me();
        $list = Invitation::where('inviter_id', $me->id)->latest()->get();

        return response()->json([
            'invitations' => $list->map(fn($i) => [
                'course_id'     => $i->course_id,
                'invited_email' => $i->invited_email,
                'token'         => $i->token,
                'status'        => $i->status,
                'role'          => $i->role,
            ])->values(),
        ]);
    }

    public function cancelInvite($invitationId)
    {
        $me = $this->me();
        $inv = Invitation::findOrFail($invitationId);

        $course = Course::findOrFail($inv->course_id);
        $isInviter = ((int)$inv->inviter_id === (int)$me->id);

        if (!$isInviter && !$this->canManage($course)) {
            return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);
        }

        $inv->status = 'cancelled';
        $inv->responded_at = $inv->responded_at ?? now();
        $inv->save();

        return response()->json(['message' => 'Invitation cancelled']);
    }

    public function acceptInvitation(string $token)
    {
        $me = $this->me();
        $inv = Invitation::where('token', $token)->first();

        if (!$inv) return response()->json(['error'=>'Invitation not found'], 404);
        if ($inv->hasExpired()) return response()->json(['error'=>'Invitation expired'], 422);

        $invitedNorm = $this->canonicalEmail($inv->invited_email);
        $meNorm      = $this->canonicalEmail($me->email);
        if ($invitedNorm !== $meNorm) {
            return response()->json(['error'=>'Not your invitation'], Http::HTTP_UNAUTHORIZED);
        }

        $course = Course::findOrFail($inv->course_id);

        $pivotRole = $inv->role ?: 'member';
        if ($pivotRole === 'user') $pivotRole = 'member';

        return DB::transaction(function () use ($inv, $course, $me, $pivotRole) {
            if ($inv->status === 'accepted') {
                $exists = DB::table('courses_users')
                    ->where('course_id',$course->id)->where('user_id',$me->id)->exists();
                if (!$exists) {
                    DB::table('courses_users')->insert([
                        'course_id'=>$course->id,
                        'user_id'  =>$me->id,
                        'role'     =>$pivotRole,
                        'status'   =>'accepted',
                        'created_at'=>now(),
                        'updated_at'=>now(),
                    ]);
                }
                return response()->json(['message'=>'Already accepted','course_id'=>$course->id]);
            }

            $exists = DB::table('courses_users')
                ->where('course_id',$course->id)->where('user_id',$me->id)->exists();

            if ($exists) {
                DB::table('courses_users')
                    ->where('course_id',$course->id)->where('user_id',$me->id)
                    ->update(['role'=>$pivotRole,'status'=>'accepted','updated_at'=>now()]);
            } else {
                DB::table('courses_users')->insert([
                    'course_id'=>$course->id,
                    'user_id'  =>$me->id,
                    'role'     =>$pivotRole,
                    'status'   =>'accepted',
                    'created_at'=>now(),
                    'updated_at'=>now(),
                ]);
            }

            $inv->status       = 'accepted';
            $inv->responded_at = now();
            $inv->user_id      = $me->id;
            $inv->save();

            return response()->json(['message'=>'Invitation accepted','course_id'=>$course->id]);
        });
    }

    public function rejectInvitation(string $token)
    {
        $me = $this->me();
        $inv = Invitation::where('token', $token)->first();

        if (!$inv) return response()->json(['error'=>'Invitation not found'], 404);
        if ($inv->hasExpired()) return response()->json(['error'=>'Invitation expired'], 422);

        $invitedNorm = $this->canonicalEmail($inv->invited_email);
        $meNorm      = $this->canonicalEmail($me->email);
        if ($invitedNorm !== $meNorm) {
            return response()->json(['error'=>'Not your invitation'], Http::HTTP_UNAUTHORIZED);
        }

        if ($inv->status === 'rejected') {
            return response()->json(['message'=>'Already rejected']);
        }

        $inv->status       = 'rejected';
        $inv->responded_at = now();
        $inv->user_id      = $me->id;
        $inv->save();

        return response()->json(['message'=>'Invitation rejected']);
    }
}
