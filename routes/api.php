<?php

use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\TestController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\NoteController;
use Illuminate\Support\Facades\Route;

// Public
Route::post('/login', [UserController::class, 'login']);
Route::post('/users/register', [UserController::class, 'store']);
Route::post('/refresh', [UserController::class, 'refresh']);

Route::middleware('auth:api')->group(function () {

    Route::prefix('me')->group(function () {

        Route::get('/dashboard', [DashboardController::class, 'dashboard']);
        // Profile
        Route::get('/profile', [UserController::class, 'show']);
        Route::get('/profile/avatar', [UserController::class, 'downloadAvatar']);
        Route::post('/profile/avatar', [UserController::class, 'updateAvatar']);
        Route::patch('/profile', [UserController::class, 'update']);
        Route::delete('/profile', [UserController::class, 'destroy']);
        Route::post('/logout', [UserController::class, 'logout']);

        // COURSES
        Route::get('/courses',                 [CourseController::class, 'index']);
        Route::get('/courses/{id}/avatar',     [CourseController::class, 'downloadAvatar']);
        Route::post('/courses/{id}/avatar',     [CourseController::class, 'updateAvatar']);
        Route::post('/courses',                [CourseController::class, 'store']);
        Route::patch('/courses/{id}',          [CourseController::class, 'update']);
        Route::delete('/courses/{id}',         [CourseController::class, 'destroy']);

        // NOTES
        Route::get('/notes',                    [NoteController::class, 'index']); // Zwraca notatki z tablicą 'files'
        Route::post('/notes',                   [NoteController::class, 'store']); // Oczekuje 'files[]' zamiast 'file'
        Route::get('/notes/{id}',               [NoteController::class, 'show'])->where('id', '[0-9]+'); // Zwraca notatkę z tablicą 'files'
        Route::match(['put', 'patch'], '/notes/{id}', [NoteController::class, 'edit'])->where('id', '[0-9]+'); // Aktualizuje tylko metadane (title, desc, is_private)
        Route::delete('/notes/{id}',            [NoteController::class, 'destroy'])->where('id', '[0-9]+'); // Usuwa notatkę i powiązane pliki (przez model event)

        Route::post('/notes/{noteId}/files', [NoteController::class, 'addFileToNote'])
            ->where('noteId', '[0-9]+')
            ->name('notes.files.add'); // Dodawanie nowego pliku do istniejącej notatki

        Route::delete('/notes/{noteId}/files/{fileId}', [NoteController::class, 'deleteFileFromNote'])
            ->where(['noteId' => '[0-9]+', 'fileId' => '[0-9]+'])
            ->name('notes.files.delete'); // Usuwanie konkretnego pliku z notatki

        Route::get('/notes/{noteId}/files/{fileId}/download', [NoteController::class, 'downloadNoteFile'])
            ->where(['noteId3' => '[0-9]+', 'fileId' => '[0-9]+'])
            ->name('notes.files.download'); // Pobieranie konkretnego pliku

        Route::post('/notes/{noteId}/share/{courseId}',   [NoteController::class, 'shareNoteWithCourse'])
            ->where(['noteId' => '[0-9]+', 'courseId' => '[0-9]+']);
        Route::delete('/notes/{noteId}/share/{courseId}', [NoteController::class, 'unshareNoteFromCourse'])
            ->where(['noteId' => '[0-9]+', 'courseId' => '[0-9]+']);

        // TESTS (per user)
        Route::get('/tests',                                   [TestController::class, 'indexForUser']);
        Route::post('/tests',                                  [TestController::class, 'storeForUser']);
        Route::get('/tests/{id}',                              [TestController::class, 'showForUser']);
        Route::put('/tests/{id}',                              [TestController::class, 'updateForUser']);
        Route::delete('/tests/{id}',                           [TestController::class, 'destroyForUser']);
        Route::get('/tests/{testId}/questions',                [TestController::class, 'questionsForUser']);
        Route::post('/tests/{testId}/questions',               [TestController::class, 'storeQuestion']);
        Route::put('/tests/{testId}/questions/{questionId}',   [TestController::class, 'updateQuestion']);
        Route::delete('/tests/{testId}/questions/{questionId}',[TestController::class, 'destroyQuestion']);
        Route::get('/tests/{testId}/questions/{questionId}/answers',  [TestController::class, 'getAnswersForQuestion']);
        Route::post('/tests/{testId}/questions/{questionId}/answers', [TestController::class, 'storeAnswer']);
        Route::put('/tests/{testId}/questions/{questionId}/answers/{answerId}', [TestController::class, 'updateAnswer']);
        Route::delete('/tests/{testId}/questions/{questionId}/answers/{answerId}', [TestController::class, 'destroyAnswer']);
        Route::post('/tests/{testId}/share',                   [TestController::class, 'shareTestWithCourse']);
        Route::delete('/tests/{testId}/share',                   [TestController::class, 'unShareTestWithCourse']);

    });

    // COURSES – akcje poza /me (wszędzie auth:api)
    Route::post('/courses/{courseId}/invite-user', [InvitationController::class, 'inviteUser']);
    Route::delete('/courses/{courseId}/leave', [CourseController::class, 'leaveCourse']);
    Route::post('/courses/{courseId}/remove-user',               [CourseController::class, 'removeUser']);
    Route::delete('/courses/{courseId}/users/{userId}/notes',    [CourseController::class, 'purgeUserNotesInCourse']);
    Route::delete('/courses/{courseId}/users/{userId}/tests',    [CourseController::class, 'purgeUserTestsInCourse']);
    Route::delete('/courses/{courseId}/notes/{noteId}',          [CourseController::class, 'unshareNoteAdmin']);
    Route::delete('/courses/{courseId}/tests/{testId}',          [CourseController::class, 'unshareTestAdmin']);
    Route::patch('/courses/{courseId}/users/{userId}/role',      [CourseController::class, 'setUserRole']);
    // (opcjonalne) zmiana roli po e-mailu
    Route::post('/courses/{courseId}/set-role-by-email',         [CourseController::class, 'setUserRoleByEmail']);

    // INVITATIONS
    Route::get('/me/invitations-received', [InvitationController::class, 'invitationsReceived']);
    Route::get('/me/invitations-sent',     [InvitationController::class, 'invitationsSent']);
    Route::post('/invitations/{token}/accept', [InvitationController::class, 'acceptInvitation']);
    Route::post('/invitations/{token}/reject', [InvitationController::class, 'rejectInvitation']);

    // TESTS in  ubuntu@
    Route::get('/courses/{courseId}/tests',                 [TestController::class, 'indexForCourse']);
    Route::post('/courses/{courseId}/tests',                [TestController::class, 'storeForCourse']);
    Route::get('/courses/{courseId}/tests/{testId}',        [TestController::class, 'showForCourse']);
    Route::put('/courses/{courseId}/tests/{testId}',        [TestController::class, 'updateForCourse']);
    Route::delete('/courses/{courseId}/tests/{testId}',     [TestController::class, 'destroyForCourse']);

    // Widoki kursu (za auth)
    Route::get('/courses/{courseId}/users', [UserController::class, 'usersForCourse']);
    Route::get('/courses/{courseId}/notes', [NoteController::class, 'notesForCourse']);
});
