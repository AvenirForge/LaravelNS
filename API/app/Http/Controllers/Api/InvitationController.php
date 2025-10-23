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

class InvitationController extends Controller
{
    /**
     * Kanoniczna postać e-maila:
     * - trim,
     * - lowercase (mb),
     * - IDN: domena na ASCII (punycode) jeśli możliwe.
     */
    private function canonicalEmail(string $email): string
    {
        $email = trim(mb_strtolower($email));

        if (function_exists('idn_to_ascii') && str_contains($email, '@')) {
            [$local, $domain] = explode('@', $email, 2);
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii) {
                $email = $local . '@' . $ascii;
            }
        }

        return $email;
    }

    private function canManage(Course $course): bool
    {
        $me = Auth::user();
        if (!$me) return false;
        if ((int)$me->id === (int)$course->user_id) return true;

        $pivot = $course->users()->where('user_id', $me->id)->first();
        $role  = $pivot?->pivot?->role;
        return in_array($role, ['owner', 'admin', 'moderator'], true);
    }

    /**
     * Zwięzły payload użytkownika do zwrotek API.
     */
    private function userPayload(?User $u): ?array
    {
        if (!$u) return null;

        return [
            'id'         => $u->id,
            'name'       => $u->name,
            'email'      => $u->email,
            'avatar_url' => $u->avatar_url, // accessor w modelu User
        ];
    }

    /**
     * Zwięzły payload kursu do zwrotek API (z URL avatara i właścicielem).
     * Uwaga: jeśli brak avataru → avatar_url = null (nie wymuszamy fallbacku).
     */
    private function coursePayload(?Course $course): ?array
    {
        if (!$course) return null;

        // Owner (preferujemy relację 'user', w razie braku – SELECT)
        $owner = null;
        if (method_exists($course, 'user')) {
            $course->loadMissing(['user:id,name,email,avatar']);
            $owner = $course->user;
        } else {
            $owner = User::select('id','name','email','avatar')->find($course->user_id);
        }

        $avatarUrl = $course->avatar ? Storage::disk('public')->url($course->avatar) : null;

        return [
            'id'           => $course->id,
            'title'        => $course->title,
            'description'  => $course->description,
            'type'         => $course->type,
            'avatar_path'  => $course->avatar,
            'avatar_url'   => $avatarUrl,
            'owner'        => $this->userPayload($owner),
            'created_at'   => optional($course->created_at)?->toISOString(),
            'updated_at'   => optional($course->updated_at)?->toISOString(),
        ];
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

        $emailRaw  = $data['email'];
        $emailNorm = $this->canonicalEmail($emailRaw);

        // Jeśli istnieje user o tym e-mailu (po formie kanonicznej), złap jego ID:
        $existingUser = User::whereRaw('LOWER(email) = ?', [$emailNorm])->first();
        $existingUserId = $existingUser?->id;

        return DB::transaction(function () use ($course, $data, $emailRaw, $emailNorm, $existingUserId) {
            $rejectedCount = Invitation::where('course_id', $course->id)
                ->where(function ($q) use ($emailNorm, $existingUserId) {
                    $q->whereRaw('LOWER(TRIM(invited_email)) = ?', [$emailNorm]);
                    if ($existingUserId) {
                        $q->orWhere('user_id', $existingUserId);
                    }
                })
                ->where('status', 'rejected')
                ->lockForUpdate()
                ->count();

            // Blokada 4-tego zaproszenia po 3 odrzuceniach
            if ($rejectedCount >= 3) {
                return response()->json([
                    'error' => 'Too many rejections for this email. Further invites are blocked.',
                ], 422);
            }

            $role = $data['role'] ?? 'member';
            if ($role === 'user') {
                $role = 'member';
            }

            $invite = Invitation::create([
                'course_id'     => $course->id,
                'inviter_id'    => Auth::id(),
                'user_id'       => $existingUserId,
                'invited_email' => $emailRaw,  // przechowuj „raw”
                'status'        => 'pending',
                'role'          => $role,
                'token'         => Str::random(48),
                'expires_at'    => now()->addDays(14),
            ]);

            // Rozszerzona odpowiedź: meta kursu + zapraszający
            $inviter = Auth::user();

            return response()->json([
                'message' => 'Invitation created',
                'invite'  => [
                    'id'           => $invite->id,
                    'token'        => $invite->token,
                    'email'        => $invite->invited_email,
                    'status'       => $invite->status,
                    'role'         => $invite->role,
                    'expires_at'   => optional($invite->expires_at)?->toISOString(),
                    'responded_at' => optional($invite->responded_at)?->toISOString(),
                    'course'       => $this->coursePayload($course),
                    'inviter'      => $this->userPayload($inviter),
                ],
            ], 200);
        });
    }

    /** GET /api/me/invitations-received */
    public function invitationsReceived()
    {
        $me = Auth::user();

        // Dopasowanie po kanonicznej formie e-maila + fallback po user_id
        $meNorm = $this->canonicalEmail($me->email);

        $list = Invitation::where(function ($q) use ($meNorm, $me) {
            $q->whereRaw('LOWER(TRIM(invited_email)) = ?', [$meNorm])
                ->orWhere('user_id', $me->id);
        })
            ->latest()
            ->get();

        // Zmapuj na bogatszy payload (kurs + zapraszający)
        $items = $list->map(function (Invitation $i) {
            $course  = Course::find($i->course_id);
            $inviter = User::find($i->inviter_id);

            return [
                'id'           => $i->id,
                'course_id'    => $i->course_id,
                'token'        => $i->token,
                'status'       => $i->status,
                'role'         => $i->role,
                'expires_at'   => optional($i->expires_at)?->toISOString(),
                'responded_at' => optional($i->responded_at)?->toISOString(),
                'course'       => $this->coursePayload($course),
                'inviter'      => $this->userPayload($inviter),
            ];
        })->values();

        return response()->json([
            'invitations' => $items,
        ]);
    }

    /** GET /api/me/invitations-sent */
    public function invitationsSent()
    {
        $me = Auth::user();

        $list = Invitation::where('inviter_id', $me->id)
            ->latest()
            ->get();

        $items = $list->map(function (Invitation $i) {
            $course   = Course::find($i->course_id);
            $invitedU = $i->user_id ? User::find($i->user_id) : null;

            return [
                'id'            => $i->id,
                'course_id'     => $i->course_id,
                'invited_email' => $i->invited_email,
                'token'         => $i->token,
                'status'        => $i->status,
                'role'          => $i->role,
                'expires_at'    => optional($i->expires_at)?->toISOString(),
                'responded_at'  => optional($i->responded_at)?->toISOString(),
                'course'        => $this->coursePayload($course),
                // opcjonalnie pokaż profil zapraszanego, jeśli istnieje w systemie
                'invitee'       => $this->userPayload($invitedU),
            ];
        })->values();

        return response()->json([
            'invitations' => $items,
        ]);
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

        // Porównanie po formie kanonicznej (odporne na case/IDN/whitespace)
        $invitedNorm = $this->canonicalEmail($inv->invited_email);
        $meNorm      = $this->canonicalEmail($me->email);
        if ($invitedNorm !== $meNorm) {
            return response()->json(['error' => 'Not your invitation'], Http::HTTP_UNAUTHORIZED);
        }

        $course = Course::findOrFail($inv->course_id);

        // Normalizacja roli na pivot: 'user' → 'member'
        $pivotRole = $inv->role ?: 'member';
        if ($pivotRole === 'user') {
            $pivotRole = 'member';
        }

        return DB::transaction(function () use ($inv, $course, $me, $pivotRole) {
            // Idempotencja: jeśli już accepted, dołóż/napraw pivot i wyjdź
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
                    'course'    => $this->coursePayload($course),
                    'inviter'   => $this->userPayload(User::find($inv->inviter_id)),
                ]);
            }

            // 1) Dołącz/aktualizuj pivota
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

            // 2) Oznacz zaproszenie jako accepted
            $inv->status       = 'accepted';
            $inv->responded_at = now();
            $inv->user_id      = $me->id;
            $inv->save();

            return response()->json([
                'message'   => 'Invitation accepted',
                'course_id' => $course->id,
                'course'    => $this->coursePayload($course),
                'inviter'   => $this->userPayload(User::find($inv->inviter_id)),
            ]);
        });
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

        // Porównanie po formie kanonicznej (odporne na case/IDN/whitespace)
        $invitedNorm = $this->canonicalEmail($inv->invited_email);
        $meNorm      = $this->canonicalEmail($me->email);
        if ($invitedNorm !== $meNorm) {
            return response()->json(['error' => 'Not your invitation'], Http::HTTP_UNAUTHORIZED);
        }

        if ($inv->status === 'rejected') {
            return response()->json([
                'message' => 'Already rejected',
                'invite'  => [
                    'id'           => $inv->id,
                    'status'       => $inv->status,
                    'course'       => $this->coursePayload(Course::find($inv->course_id)),
                    'inviter'      => $this->userPayload(User::find($inv->inviter_id)),
                    'responded_at' => optional($inv->responded_at)?->toISOString(),
                ],
            ]);
        }

        $inv->status       = 'rejected';
        $inv->responded_at = now();
        $inv->user_id      = $me->id;
        $inv->save();

        return response()->json([
            'message' => 'Invitation rejected',
            'invite'  => [
                'id'           => $inv->id,
                'status'       => $inv->status,
                'course'       => $this->coursePayload(Course::find($inv->course_id)),
                'inviter'      => $this->userPayload(User::find($inv->inviter_id)),
                'responded_at' => optional($inv->responded_at)?->toISOString(),
            ],
        ]);
    }
}
