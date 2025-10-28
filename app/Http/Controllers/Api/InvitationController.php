<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as Http;
// --- CORRECT IMPORT ---
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
// --- END CORRECTION ---
use Illuminate\Database\Query\Builder as QueryBuilder; // Keep for potential DB::table usage

class InvitationController extends Controller
{
    // Helpers (canonicalEmail, roleInCourse, canManage remain the same as previous correct version)
    private function me(): ?User { return Auth::guard('api')->user(); }

    private function canonicalEmail(string $email): string {
        $email = trim(mb_strtolower($email));
        if (!str_contains($email, '@')) return $email;
        [$local, $domain] = explode('@', $email, 2); $domainAscii = $domain;
        if (function_exists('idn_to_ascii')) { $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46); if ($ascii !== false) $domainAscii = $ascii; }
        if (in_array($domainAscii, ['gmail.com', 'googlemail.com'], true)) { $plusPos = strpos($local, '+'); if ($plusPos !== false) $local = substr($local, 0, $plusPos); $local = str_replace('.', '', $local); }
        return $local.'@'.$domainAscii;
    }

    private function roleInCourse(Course $course, int $userId): string {
        if ((int)$userId === (int)$course->user_id) return 'owner';
        $pivot = DB::table('courses_users')->where('course_id', $course->id)->where('user_id', $userId)->first(['role', 'status']);
        $acceptedStatuses = ['accepted', 'active', 'approved', 'joined']; // Define accepted statuses
        if (!$pivot || !in_array($pivot->status, $acceptedStatuses, true)) return 'guest'; // Check status
        $role = $pivot->role; return (empty($role) || $role === 'user') ? 'member' : $role;
    }

    private function canManage(Course $course): bool {
        $me = $this->me(); if (!$me) return false;
        if ((int)$me->id === (int)$course->user_id) return true;
        $role = $this->roleInCourse($course, (int)$me->id); return in_array($role, ['owner','admin','moderator'], true);
    }

    // POST /api/courses/{courseId}/invite-user
    public function inviteUser(Request $request, $courseId) {
        $course = Course::findOrFail($courseId);
        if (!$this->canManage($course)) return response()->json(['error' => 'Permission denied'], Http::HTTP_FORBIDDEN);

        $data = $request->validate([ 'email' => 'required|string|email|max:255', 'role' => 'sometimes|string|in:owner,admin,moderator,user,member']);
        $emailRaw = $data['email']; $emailNorm = $this->canonicalEmail($emailRaw);
        $existingUser = User::whereRaw('LOWER(TRIM(email)) = ?', [$emailNorm])->first(); $existingUserId = $existingUser?->id;

        if ($existingUserId) {
            $acceptedStatuses = ['accepted', 'active', 'approved', 'joined'];
            $isAlreadyMember = DB::table('courses_users')->where('course_id', $course->id)->where('user_id', $existingUserId)->whereIn('status', $acceptedStatuses)->exists();
            if ($isAlreadyMember) return response()->json(['message' => 'User is already an active member', 'errors' => ['email' => ['User already member']]], Http::HTTP_CONFLICT);
        }

        return DB::transaction(function () use ($course, $data, $emailRaw, $emailNorm, $existingUserId) {
            $rejectionQuery = Invitation::where('course_id', $course->id)->where('status', 'rejected');
            // --- CORRECT TYPE HINT ---
            $rejectionQuery->where(function (EloquentBuilder $query) use ($emailNorm, $existingUserId) {
                // --- END CORRECTION ---
                $query->whereRaw('LOWER(TRIM(invited_email)) = ?', [$emailNorm]);
                if ($existingUserId) $query->orWhere('user_id', $existingUserId);
            });
            $rejectedCount = $rejectionQuery->count();

            if ($rejectedCount >= 3) return response()->json(['message' => 'Too many rejections', 'errors' => ['email' => ['Invitation blocked']]], Http::HTTP_UNPROCESSABLE_ENTITY);

            $role = $data['role'] ?? 'member'; if ($role === 'user') $role = 'member';
            if ($role === 'owner') return response()->json(['message' => 'Cannot invite as owner', 'errors' => ['role' => ['Owner role forbidden']]], Http::HTTP_UNPROCESSABLE_ENTITY);

            $invite = Invitation::create([ 'course_id' => $course->id, 'inviter_id' => Auth::guard('api')->id(), 'user_id' => $existingUserId, 'invited_email' => $emailRaw, 'status' => 'pending', 'role' => $role, 'token' => Str::random(48), 'expires_at' => now()->addDays(14), ]);
            return response()->json([ 'message' => 'Invitation sent', 'invitation' => [ 'id' => $invite->id, 'token' => $invite->token, 'invited_email' => $invite->invited_email, 'status' => $invite->status, 'role' => $invite->role, 'expires_at' => $invite->expires_at?->toIso8601String(), ] ], Http::HTTP_CREATED);
        });
    }

    // GET /api/me/invitations-received
    public function invitationsReceived(): \Illuminate\Http\JsonResponse
    {
        $me = $this->me();
        if (!$me) {
            return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);
        }
        $meNorm = $this->canonicalEmail($me->email);
        $list = Invitation::with(['course:id,title,type,user_id,avatar', 'inviter:id,name,email,avatar'])
            // --- CORRECT TYPE HINT ---
            ->where(function (EloquentBuilder $query) use ($meNorm, $me) {
                // --- END CORRECTION ---
                $query->whereRaw('LOWER(TRIM(invited_email)) = ?', [$meNorm])->orWhere('user_id', $me->id);
            })->latest('created_at')->get();

        return response()->json(['invitations' => $list->map(function (Invitation $i) {

            // --- ZMODYFIKOWANA LOGIKA URL ---
            // Zakładamy, że $i->course->avatar przechowuje ścieżkę pliku, np. 'courses_avatars/plik.jpg'
            // Jeśli $i->course->avatar jest nullem, Storage::url(null) zwróci null.
            $courseAvatarPath = $i->course?->avatar;
            $cA = $courseAvatarPath ? Storage::disk('public')->url($courseAvatarPath) : null;

            // Podobnie dla avatara zapraszającego (Inviter/User)
            $inviterAvatarPath = $i->inviter?->avatar;
            $iA = $inviterAvatarPath ? Storage::disk('public')->url($inviterAvatarPath) : null;
            // --- KONIEC MODYFIKACJI ---

            return [
                'id' => $i->id,
                'course_id' => $i->course_id,
                'token' => $i->token,
                'status' => $i->status,
                'role' => $i->role,
                'invited_email' => $i->invited_email,
                'expires_at' => $i->expires_at?->toIso8601String(),
                'responded_at' => $i->responded_at?->toIso8601String(),
                'created_at' => $i->created_at?->toIso8601String(),
                'updated_at' => $i->updated_at?->toIso8601String(),
                'is_expired' => $i->hasExpired(),
                'course' => $i->course ? [
                    'id' => $i->course->id,
                    'title' => $i->course->title,
                    'type' => $i->course->type,
                    'user_id' => $i->course->user_id,
                    'avatar_url' => $cA // Użycie wygenerowanego URL
                ] : null,
                'inviter' => $i->inviter ? [
                    'id' => $i->inviter->id,
                    'name' => $i->inviter->name ?? null,
                    'email' => $i->inviter->email,
                    'avatar_url' => $iA // Użycie wygenerowanego URL
                ] : null
            ];
        })->values()]);
    }

    // GET /api/me/invitations-sent (Unchanged)
    public function invitationsSent() {
        $me = $this->me(); if (!$me) return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);
        $list = Invitation::with('course:id,title')->where('inviter_id', $me->id)->latest('created_at')->get();
        return response()->json(['invitations' => $list->map(fn(Invitation $i) => ['id'=>$i->id, 'course_id'=>$i->course_id, 'course_title'=>$i->course?->title, 'invited_email'=>$i->invited_email, 'token'=>$i->token, 'status'=>$i->status, 'role'=>$i->role, 'expires_at'=>$i->expires_at?->toIso8601String(), 'created_at'=>$i->created_at?->toIso8601String()])->values()]);
    }

    // POST /api/invitations/{invitationId}/cancel (Unchanged)
    public function cancelInvite($invitationId) {
        $me = $this->me(); if (!$me) return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);
        $inv = Invitation::find($invitationId); if (!$inv) return response()->json(['error' => 'Not found'], Http::HTTP_NOT_FOUND);
        $course = Course::find($inv->course_id); if (!$course) return response()->json(['error' => 'Course not found'], Http::HTTP_NOT_FOUND);
        $isInviter = ((int)$inv->inviter_id === (int)$me->id);
        if (!$isInviter && !$this->canManage($course)) return response()->json(['error' => 'Forbidden'], Http::HTTP_FORBIDDEN);
        if ($inv->status !== 'pending') return response()->json(['message' => 'Cannot cancel'], Http::HTTP_CONFLICT);
        $inv->status = 'cancelled'; $inv->responded_at = now(); $inv->save();
        return response()->json(['message' => 'Cancelled']);
    }

    // POST /api/invitations/{token}/accept
    public function acceptInvitation(string $token) {
        $me = $this->me(); if (!$me) return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);
        $inv = Invitation::where('token', $token)->first();
        if (!$inv) return response()->json(['error' => 'Not found'], 404);
        if ($inv->hasExpired()) return response()->json(['error' => 'Expired'], 410);
        if ($inv->status !== 'pending') return response()->json(['message' => 'Already processed'], Http::HTTP_CONFLICT);
        $invitedNorm = $this->canonicalEmail($inv->invited_email); $meNorm = $this->canonicalEmail($me->email); $matchesUserId = ($inv->user_id && (int)$inv->user_id === (int)$me->id);
        if ($invitedNorm !== $meNorm && !$matchesUserId) return response()->json(['error' => 'Forbidden'], Http::HTTP_FORBIDDEN);
        $course = Course::find($inv->course_id); if (!$course) return response()->json(['error' => 'Course not found'], Http::HTTP_NOT_FOUND);
        $pivotRole = $inv->role ?: 'member'; if ($pivotRole === 'user') $pivotRole = 'member';

        return DB::transaction(function () use ($inv, $course, $me, $pivotRole, $meNorm) {
            $course->users()->syncWithoutDetaching([$me->id => ['role' => $pivotRole, 'status' => 'accepted']]);
            $now = now();
            Invitation::where('course_id', $course->id)->where('id', '!=', $inv->id)->where('status', 'pending')
                // --- CORRECT TYPE HINT ---
                ->where(function (EloquentBuilder $query) use ($me, $meNorm) {
                    // --- END CORRECTION ---
                    $query->where('user_id', $me->id)->orWhereRaw('LOWER(TRIM(invited_email)) = ?', [$meNorm]);
                })->update(['status' => 'cancelled', 'responded_at' => $now]);
            $inv->status = 'accepted'; $inv->responded_at = $now; if (!$inv->user_id) $inv->user_id = $me->id; $inv->save();
            $course->load('users:id,name');
            return response()->json(['message' => 'Accepted, others cancelled', 'course' => $course, 'your_role' => $pivotRole]);
        });
    }

    // POST /api/invitations/{token}/reject (Unchanged)
    public function rejectInvitation(string $token) {
        $me = $this->me(); if (!$me) return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);
        $inv = Invitation::where('token', $token)->first();
        if (!$inv) return response()->json(['error' => 'Not found'], 404);
        if ($inv->hasExpired()) return response()->json(['error' => 'Expired'], 410);
        if ($inv->status !== 'pending') return response()->json(['message' => 'Already processed'], Http::HTTP_CONFLICT);
        $invitedNorm = $this->canonicalEmail($inv->invited_email); $meNorm = $this->canonicalEmail($me->email); $matchesUserId = ($inv->user_id && (int)$inv->user_id === (int)$me->id);
        if ($invitedNorm !== $meNorm && !$matchesUserId) return response()->json(['error' => 'Forbidden'], Http::HTTP_FORBIDDEN);
        $inv->status = 'rejected'; $inv->responded_at = now(); if (!$inv->user_id) $inv->user_id = $me->id; $inv->save();
        return response()->json(['message' => 'Rejected']);
    }
}
