<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as Http;

class InvitationController extends Controller
{
    private function canManage(Course $course): bool
    {
        $me = Auth::user();
        if (!$me) return false;
        if ((int)$me->id === (int)$course->user_id) return true;

        $pivot = $course->users()->where('user_id', $me->id)->first();
        $role  = $pivot?->pivot?->role;
        return in_array($role, ['owner', 'admin', 'moderator'], true);
    }

    /** POST /api/courses/{courseId}/invite-user */
    public function inviteUser(Request $request, $courseId)
    {
        $course = Course::findOrFail($courseId);

        if (!$this->canManage($course)) {
            return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);
        }

        $data = $request->validate([
            'email' => 'required|email',
            'role'  => 'sometimes|in:owner,admin,moderator,user,member',
        ]);

        // Blokada 4-tego zaproszenia po 3 odrzuceniach
        $rejectedCount = Invitation::where('course_id', $course->id)
            ->where('invited_email', $data['email'])
            ->where('status', 'rejected')
            ->count();

        if ($rejectedCount >= 3) {
            return response()->json([
                'error' => 'Too many rejections for this email. Further invites are blocked.'
            ], 422);
        }

        $token = Str::random(48);

        $invite = Invitation::create([
            'course_id'     => $course->id,
            'inviter_id'    => Auth::id(),
            'user_id'       => optional(User::where('email', $data['email'])->first())->id,
            'invited_email' => $data['email'],
            'status'        => 'pending',
            'role'          => $data['role'] ?? 'member', // preferujemy 'member' jako domyślną rolę
            'token'         => $token,
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
    }

    /** GET /api/me/invitations-received */
    public function invitationsReceived()
    {
        $me = Auth::user();
        $list = Invitation::where('invited_email', $me->email)
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

    /** GET /api/me/invitations-sent */
    public function invitationsSent()
    {
        $me = Auth::user();
        $list = Invitation::where('inviter_id', $me->id)
            ->latest()
            ->get();

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

    /** POST /api/invitations/{invitationId}/cancel */
    public function cancelInvite($invitationId)
    {
        $me = Auth::user();
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

    /** POST /api/invitations/{token}/accept */
    public function acceptInvitation(string $token)
    {
        $me = Auth::user();
        $inv = Invitation::where('token', $token)->first();

        if (!$inv) {
            return response()->json(['error' => 'Invitation not found'], 404);
        }
        if ($inv->hasExpired()) {
            return response()->json(['error' => 'Invitation expired'], 422);
        }
        if ($inv->invited_email !== $me->email) {
            return response()->json(['error' => 'Not your invitation'], Http::HTTP_UNAUTHORIZED);
        }

        $course = Course::findOrFail($inv->course_id);

        // Normalizacja roli na pivot (legacy bazy bez 'user' w ENUM):
        // 'user' → 'member'; pozostałe przechodzą bez zmian.
        $pivotRole = $inv->role ?: 'member';
        if ($pivotRole === 'user') {
            $pivotRole = 'member';
        }

        // Jeśli zaproszenie już accepted — upewnij się, że pivot istnieje (idempotencja + samonaprawa)
        if ($inv->status === 'accepted') {
            $exists = $course->users()->where('user_id', $me->id)->exists();
            if (!$exists) {
                $course->users()->attach($me->id, [
                    'role'   => $pivotRole,
                    'status' => 'accepted',
                ]);
            }
            return response()->json([
                'message'   => 'Already accepted',
                'course_id' => $course->id,
            ]);
        }

        // 1) Najpierw dołącz/aktualizuj pivota (kluczowe by nie zostawiać accepted bez członkostwa)
        $exists = $course->users()->where('user_id', $me->id)->exists();
        if ($exists) {
            $course->users()->updateExistingPivot($me->id, [
                'role'   => $pivotRole,
                'status' => 'accepted',
            ]);
        } else {
            $course->users()->attach($me->id, [
                'role'   => $pivotRole,
                'status' => 'accepted',
            ]);
        }

        // 2) Dopiero teraz oznacz zaproszenie jako accepted
        $inv->status       = 'accepted';
        $inv->responded_at = now();
        $inv->user_id      = $me->id;
        $inv->save();

        return response()->json([
            'message'   => 'Invitation accepted',
            'course_id' => $course->id,
        ]);
    }

    /** POST /api/invitations/{token}/reject */
    public function rejectInvitation(string $token)
    {
        $me = Auth::user();
        $inv = Invitation::where('token', $token)->first();

        if (!$inv) {
            return response()->json(['error' => 'Invitation not found'], 404);
        }
        if ($inv->hasExpired()) {
            return response()->json(['error' => 'Invitation expired'], 422);
        }
        if ($inv->invited_email !== $me->email) {
            return response()->json(['error' => 'Not your invitation'], Http::HTTP_UNAUTHORIZED);
        }

        if ($inv->status === 'rejected') {
            return response()->json(['message' => 'Already rejected']);
        }

        $inv->status       = 'rejected';
        $inv->responded_at = now();
        $inv->user_id      = $me->id;
        $inv->save();

        return response()->json(['message' => 'Invitation rejected']);
    }
}
