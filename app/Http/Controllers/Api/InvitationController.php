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
    // ─────────────────────────────────────────────────────────────────────────────
    // Helpers (guard, kanonizacja e-maila, spójna determinacja roli w kursie)
    // ─────────────────────────────────────────────────────────────────────────────
    private function me()
    {
        return Auth::guard('api')->user();
    }

    private function canonicalEmail(string $email): string
    {
        // ZAWSZE normalizujemy Gmail (kropki/aliasy), niezależnie od dostępności intl.
        $email = trim(mb_strtolower($email));
        if (!str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);
        $domainAscii = $domain;

        // IDN → ASCII (jeśli dostępne). Brak intl nie blokuje normalizacji Gmaila.
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii) {
                $domainAscii = $ascii;
            }
        }

        // Gmail / Googlemail: usuń aliasy po '+' i kropki w lokalnej części
        if (in_array($domainAscii, ['gmail.com', 'googlemail.com'], true)) {
            $plusPos = strpos($local, '+');
            if ($plusPos !== false) {
                $local = substr($local, 0, $plusPos);
            }
            $local = str_replace('.', '', $local);
            // Uwaga: nie wymuszamy zmiany domeny na gmail.com — zachowujemy spójność z istniejącą logiką
        }

        return $local.'@'.$domainAscii;
    }

    /**
     * Rola użytkownika w kursie: owner | admin | moderator | member | guest
     * (spójna z CourseController)
     */
    private function roleInCourse(Course $course, int $userId): string
    {
        if ((int)$userId === (int)$course->user_id) return 'owner';

        $pivot = DB::table('courses_users')
            ->where('course_id', $course->id)
            ->where('user_id', $userId)
            ->first();

        if (!$pivot) return 'guest';

        $role = $pivot->role;
        return (!$role || $role === 'user') ? 'member' : $role;
    }

    /**
     * Czy obecny użytkownik może zarządzać zaproszeniami w kursie.
     * Dopuszczalne role: owner/admin/moderator.
     */
    private function canManage(Course $course): bool
    {
        $me = $this->me();
        if (!$me) return false;

        if ((int)$me->id === (int)$course->user_id) return true;

        $role = $this->roleInCourse($course, (int)$me->id);
        return in_array($role, ['owner','admin','moderator'], true);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // POST /api/courses/{courseId}/invite-user
    // Tworzy zaproszenie (z rolą). Blokuje po 3 odrzuceniach w danym kursie.
    // ─────────────────────────────────────────────────────────────────────────────
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

        $emailRaw      = $data['email'];
        $emailLowerRaw = trim(mb_strtolower($emailRaw));          // surowa (raw) wersja do dopasowań
        $emailNorm     = $this->canonicalEmail($emailRaw);        // kanoniczna (Gmail zawsze; IDN jeśli dostępne)

        // „Norma bez IDN”, ale z zasadami Gmail — przydatne, gdy intl nieobecne na VPS
        $normNoIdn = (function (string $mail): string {
            $mail = trim(mb_strtolower($mail));
            if (!str_contains($mail, '@')) return $mail;
            [$local, $domain] = explode('@', $mail, 2);
            if (in_array($domain, ['gmail.com','googlemail.com'], true)) {
                $plusPos = strpos($local, '+');
                if ($plusPos !== false) $local = substr($local, 0, $plusPos);
                $local = str_replace('.', '', $local);
            }
            return $local.'@'.$domain;
        })($emailRaw);

        // Szukaj użytkownika po obu normach (czasem zapis w DB pasuje do jednej z nich)
        $existingUser = User::where(function ($q) use ($emailNorm, $normNoIdn) {
            $q->whereRaw('LOWER(TRIM(email)) = ?', [$emailNorm])
                ->orWhereRaw('LOWER(TRIM(email)) = ?', [$normNoIdn]);
        })
            ->first();
        $existingUserId = $existingUser?->id;

        return DB::transaction(function () use ($course, $data, $emailRaw, $emailLowerRaw, $emailNorm, $normNoIdn, $existingUserId) {
            // Licz odrzucenia w TYM kursie dopasowując po:
            //  - invited_email (rawLower / norm / normNoIdn)
            //  - user_id (jeśli znany)
            $variants = array_values(array_unique([$emailLowerRaw, $emailNorm, $normNoIdn]));

            $defaultConnection = config('database.default');

            $rejectedCount = Invitation::on($defaultConnection) // Wymuś użycie domyślnego połączenia (zapis/primary)
            ->where('course_id', $course->id)
                ->where(function ($q) use ($variants, $existingUserId) {
                    $q->where(function ($qq) use ($variants) {
                        foreach ($variants as $v) {
                            $qq->orWhereRaw('LOWER(TRIM(invited_email)) = ?', [$v]);
                        }
                    });
                    if ($existingUserId) {
                        $q->orWhere('user_id', $existingUserId);
                    }
                })
                ->where('status', 'rejected')
                ->lockForUpdate() // Zabezpiecza przed race conditions podczas odczytu i aktualizacji
                ->count();

            if ($rejectedCount >= 3) {
                return response()->json([
                    'error' => 'Too many rejections for this email. Further invites are blocked.',
                ], 422);
            }

            // Normalizacja roli na potrzeby pivota: 'user' → 'member'
            $role = $data['role'] ?? 'member';
            if ($role === 'user') $role = 'member';

            $invite = Invitation::create([
                'course_id'     => $course->id,
                'inviter_id'    => Auth::guard('api')->id(),
                'user_id'       => $existingUserId,
                'invited_email' => $emailRaw,   // przechowujemy raw (do UI/mailingu)
                'status'        => 'pending',
                'role'          => $role,       // upewnij się, że 'role' jest w $fillable
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

    // ─────────────────────────────────────────────────────────────────────────────
    // GET /api/me/invitations-received
    // (Dopasowanie po kanonicznym e-mailu; zwraca listę tokenów i meta)
    // ─────────────────────────────────────────────────────────────────────────────
    public function invitationsReceived()
    {
        $me = $this->me();
        if (!$me) {
            return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);
        }

        $meNorm = $this->canonicalEmail($me->email);

        $list = Invitation::with([
            // Minimalny, sensowny zestaw informacji o kursie
            'course'  => fn($q) => $q->select('id','title','type','user_id','avatar'),
            // Informacje o zapraszającym
            'inviter' => fn($q) => $q->select('id','name','email','avatar'),
        ])
            ->whereRaw('LOWER(TRIM(invited_email)) = ?', [$meNorm])
            ->latest()
            ->get();

        return response()->json([
            'invitations' => $list->map(function (Invitation $i) {
                return [
                    // Podstawy zaproszenia
                    'id'            => $i->id,
                    'course_id'     => $i->course_id,
                    'token'         => $i->token,
                    'status'        => $i->status,
                    'role'          => $i->role,
                    'invited_email' => $i->invited_email,
                    'expires_at'    => $i->expires_at?->toIso8601String(),
                    'responded_at'  => $i->responded_at?->toIso8601String(),
                    'created_at'    => $i->created_at?->toIso8601String(),
                    'updated_at'    => $i->updated_at?->toIso8601String(),
                    'is_expired'    => $i->hasExpired(),

                    // Kurs docelowy
                    'course' => $i->course ? [
                        'id'       => $i->course->id,
                        'title'    => $i->course->title,
                        'type'     => $i->course->type,
                        'user_id'  => $i->course->user_id,
                        'avatar'   => $i->course->avatar,
                    ] : null,

                    // Zapraszający
                    'inviter' => $i->inviter ? [
                        'id'     => $i->inviter->id,
                        'name'   => $i->inviter->name ?? null,
                        'email'  => $i->inviter->email,
                        'avatar' => $i->inviter->avatar ?? null,
                    ] : null,
                ];
            })->values(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // GET /api/me/invitations-sent
    // ─────────────────────────────────────────────────────────────────────────────
    public function invitationsSent()
    {
        $me = $this->me();

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

    // ─────────────────────────────────────────────────────────────────────────────
    // POST /api/invitations/{invitationId}/cancel
    // ─────────────────────────────────────────────────────────────────────────────
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

    // ─────────────────────────────────────────────────────────────────────────────
    // POST /api/invitations/{token}/accept
    // (mapowanie 'user'→'member' pozostaje bez zmian)
    // ─────────────────────────────────────────────────────────────────────────────
    public function acceptInvitation(string $token)
    {
        $me = $this->me();
        $inv = Invitation::where('token', $token)->first();

        if (!$inv) return response()->json(['error' => 'Invitation not found'], 404);
        if ($inv->hasExpired()) return response()->json(['error' => 'Invitation expired'], 422);

        $invitedNorm = $this->canonicalEmail($inv->invited_email);
        $meNorm      = $this->canonicalEmail($me->email);
        if ($invitedNorm !== $meNorm) {
            return response()->json(['error' => 'Not your invitation'], Http::HTTP_UNAUTHORIZED);
        }

        $course = Course::findOrFail($inv->course_id);

        // Normalizacja
        $pivotRole = $inv->role ?: 'member';
        if ($pivotRole === 'user') $pivotRole = 'member';

        return DB::transaction(function () use ($inv, $course, $me, $pivotRole) {
            // Idempotentnie
            if ($inv->status === 'accepted') {
                $exists = DB::table('courses_users')
                    ->where('course_id', $course->id)
                    ->where('user_id', $me->id)
                    ->exists();

                if (!$exists) {
                    DB::table('courses_users')->insert([
                        'course_id'  => $course->id,
                        'user_id'    => $me->id,
                        'role'       => $pivotRole,
                        'status'     => 'accepted',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                return response()->json([
                    'message'   => 'Already accepted',
                    'course_id' => $course->id,
                ]);
            }

            // Ustaw/aktualizuj pivot
            $exists = DB::table('courses_users')
                ->where('course_id', $course->id)
                ->where('user_id', $me->id)
                ->exists();

            if ($exists) {
                DB::table('courses_users')
                    ->where('course_id', $course->id)
                    ->where('user_id', $me->id)
                    ->update([
                        'role'       => $pivotRole,
                        'status'     => 'accepted',
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('courses_users')->insert([
                    'course_id'  => $course->id,
                    'user_id'    => $me->id,
                    'role'       => $pivotRole,
                    'status'     => 'accepted',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Oznacz zaproszenie jako accepted
            $inv->status       = 'accepted';
            $inv->responded_at = now();
            $inv->user_id      = $me->id;
            $inv->save();

            return response()->json([
                'message'   => 'Invitation accepted',
                'course_id' => $course->id,
            ]);
        });
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // POST /api/invitations/{token}/reject
    // ─────────────────────────────────────────────────────────────────────────────
    public function rejectInvitation(string $token)
    {
        $me = $this->me();
        $inv = Invitation::where('token', $token)->first();

        if (!$inv) return response()->json(['error' => 'Invitation not found'], 404);
        if ($inv->hasExpired()) return response()->json(['error' => 'Invitation expired'], 422);

        $invitedNorm = $this->canonicalEmail($inv->invited_email);
        $meNorm      = $this->canonicalEmail($me->email);
        if ($invitedNorm !== $meNorm) {
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
