<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Course;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

class InvitationController extends Controller
{
    public function inviteUser(Request $request, $courseId): \Illuminate\Http\JsonResponse
    {
        // Walidacja danych wejściowych
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        // Sprawdzenie, czy kurs istnieje
        $course = Course::find($courseId);
        if (!$course) {
            return response()->json(['error' => 'Course not found'], 404);
        }

        // Sprawdzenie, czy użytkownik jest administratorem kursu
        $user = Auth::user();
        $userIsOwner = $course->users()->where('user_id', $user->id)->where('role', 'owner')->exists();
        if (!$userIsOwner) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Sprawdzanie, ile razy użytkownik odrzucił zaproszenie
        $rejectedCount = Invitation::where('course_id', $courseId)
            ->where('invited_email', $request->email)
            ->where('status', 'rejected')
            ->count();

        // Jeśli użytkownik odrzucił zaproszenie 3 razy, blokujemy możliwość zapraszania
        if ($rejectedCount >= 3) {
            return response()->json(['error' => 'User has rejected invitations 3 times, cannot be invited again'], 400);
        }

        // Sprawdzenie, czy użytkownik, którego chcemy zaprosić, istnieje
        $userToInvite = User::where('email', $request->email)->first();
        if (!$userToInvite) {
            return response()->json(['error' => 'User not found'], 404);
        }

        // Sprawdzamy, czy zaproszenie już istnieje
        $existingInvite = Invitation::where('course_id', $courseId)
            ->where('invited_email', $request->email)
            ->whereIn('status', ['pending', 'accepted'])
            ->first();

        if ($existingInvite) {
            return response()->json(['error' => 'User is already invited or part of the course'], 400);
        }

        // Generowanie tokenu zaproszenia
        $token = Str::random(32);  // Generowanie unikalnego tokenu (możesz zmienić długość)

        // Ustawienie daty wygaśnięcia na 7 dni
        $expiresAt = now()->addDays(7); // Możesz zmienić czas wygaśnięcia na inny

        // Tworzymy zaproszenie
        $invite = Invitation::create([
            'course_id' => $courseId,
            'invited_email' => $request->email,
            'status' => 'pending',
            'role' => 'user',  // Możesz ustawić odpowiednią rolę
            'inviter_id' => $user->id,  // ID zapraszającego użytkownika
            'token' => $token,  // Dodanie wygenerowanego tokenu
            'expires_at' => $expiresAt,  // Ustawienie daty wygaśnięcia
        ]);

        // Sprawdzamy, czy zaproszenie zostało utworzone poprawnie
        if (!$invite) {
            return response()->json(['error' => 'Failed to create invitation'], 500);
        }

        return response()->json(['message' => 'Invitation sent successfully', 'invite' => $invite], 200);
    }



    public function cancelInvite(Request $request, $courseId, $inviteId): \Illuminate\Http\JsonResponse
    {
        // Wyszukiwanie zaproszenia
        $invite = Invitation::find($inviteId);

        if (!$invite) {
            return response()->json(['error' => 'Invitation not found'], 404);
        }

        // Sprawdzamy, czy zaproszenie należy do tego kursu
        if ($invite->course_id !== (int)$courseId) {
            return response()->json(['error' => 'Invitation does not belong to this course'], 400);
        }

        // Sprawdzamy, czy zaproszenie ma status "pending"
        if ($invite->status !== 'pending') {
            return response()->json(['error' => 'Invitation is not in pending status'], 400);
        }

        // Pobranie kursu na podstawie courseId
        $course = Course::find($courseId);
        if (!$course) {
            return response()->json(['error' => 'Course not found'], 404);
        }

        // Pobieramy zalogowanego użytkownika
        $user = Auth::user();

        // Sprawdzamy, czy użytkownik jest przypisany do kursu
        $userRole = $course->users()->where('user_id', $user->id)->first();

        if (!$userRole) {
            return response()->json(['error' => 'User is not assigned to this course'], 400);
        }

        // Teraz możemy bezpiecznie pobrać rolę z tabeli pośredniczącej
        $userRole = $userRole->pivot->role ?? '';

        // Użytkownik musi mieć rolę "owner" lub "moderator"
        if (!in_array($userRole, ['owner', 'moderator'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Anulowanie zaproszenia - zmiana statusu na "canceled"
        $invite->status = 'canceled';
        $invite->save();

        return response()->json(['message' => 'Invitation has been canceled successfully'], 200);
    }

    public function acceptInvitation(Request $request, $token)
    {
        // Wyszukiwanie zaproszenia na podstawie tokenu
        $invite = Invitation::where('token', $token)->first();

        if (!$invite) {
            return response()->json(['error' => 'Invitation not found'], 404);
        }

        // Sprawdzamy, czy zaproszenie ma status "pending"
        if ($invite->status !== 'pending') {
            return response()->json(['error' => 'Invitation is not pending'], 400);
        }

        // Sprawdzamy, czy zaproszenie jest już wygasłe
        if ($invite->expires_at < now()) {
            return response()->json(['error' => 'Invitation has expired'], 400);
        }

        // Weryfikacja tokenu w URL
        // Token byłby już zweryfikowany w zapytaniu, ponieważ używamy go w URL

        // Sprawdzamy, czy użytkownik nie jest już zapisany do kursu
        $user = Auth::user();
        $courseUser = $invite->course->users()->where('user_id', $user->id)->first();

        if ($courseUser) {
            return response()->json(['error' => 'User is already enrolled in this course'], 400);
        }

        // Dodajemy użytkownika do kursu i przypisujemy mu rolę "user"
        $invite->course->users()->attach($user->id, [
            'role' => 'user',  // Rola przypisana użytkownikowi
            'status' => 'active',  // Status użytkownika w kursie
            'created_at' => now(),  // Ustawienie daty stworzenia
            'updated_at' => now(),  // Ustawienie daty modyfikacji
        ]);

        // Zmieniamy status zaproszenia na "accepted"
        $invite->status = 'accepted';
        $invite->save();

        return response()->json([
            'message' => 'Invitation has been accepted successfully',
            'course' => $invite->course,
            'user' => $user,
        ], 200);
    }

    public function rejectInvitation(Request $request, $token): \Illuminate\Http\JsonResponse
    {
        // Wyszukiwanie zaproszenia na podstawie tokenu
        $invite = Invitation::where('token', $token)->first();

        if (!$invite) {
            return response()->json(['error' => 'Invitation not found'], 404);
        }

        // Sprawdzamy, czy zaproszenie ma status "pending"
        if ($invite->status !== 'pending') {
            return response()->json(['error' => 'Invitation is not pending'], 400);
        }

        // Sprawdzamy, czy zaproszenie jest już wygasłe
        if ($invite->expires_at < now()) {
            return response()->json(['error' => 'Invitation has expired'], 400);
        }

        // Sprawdzamy, czy zaproszenie nie zostało już zaakceptowane lub odrzucone
        if ($invite->status !== 'pending') {
            return response()->json(['error' => 'Invitation cannot be rejected'], 400);
        }

        // Sprawdzamy, czy użytkownik, który otrzymał zaproszenie, jest tym samym, który je odrzuca
        $user = Auth::user();
        if ($invite->invited_email !== $user->email) {
            return response()->json(['error' => 'You are not the invited user'], 400);
        }

        // Zmieniamy status zaproszenia na "rejected"
        $invite->status = 'rejected';
        $invite->save();

        return response()->json([
            'message' => 'Invitation has been rejected successfully',
            'invite' => $invite
        ], 200);
    }
    /**
     * Pobierz wszystkie zaproszenia (dla użytkownika)
     */
    public function invitationsReceived(): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();

        $received = Invitation::with('course', 'inviter')
            ->where('invited_email', $user->email)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'invitations' => $received,
        ]);
    }

    public function invitationsSent(): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();

        $sent = Invitation::with('course')
            ->where('inviter_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'invitations' => $sent,
        ]);
    }

}
