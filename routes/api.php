<?php

use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\TestController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\NoteController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [UserController::class, 'login']);
Route::post('/users/register', [UserController::class, 'store']);

Route::middleware('auth:api')->group(function () {

    Route::prefix('me')->group(function () {

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
        Route::post('/courses',                [CourseController::class, 'store']);
        Route::patch('/courses/{id}',            [CourseController::class, 'update']);
        Route::delete('/courses/{id}',         [CourseController::class, 'destroy']);

        // NOTES — dodany GET /notes/{id}
        Route::get('/notes',                   [NoteController::class, 'index']);
        Route::post('/notes',                  [NoteController::class, 'store']);
        Route::get('/notes/{id}',              [NoteController::class, 'show']);      // ★ NOWE
        Route::patch('/notes/{id}',            [NoteController::class, 'edit']);
        Route::delete('/notes/{id}',           [NoteController::class, 'destroy']);
        Route::post('/notes/{id}/patch',       [NoteController::class, 'patchFile']);
        Route::get('/notes/{id}/download',     [NoteController::class, 'download']);
        Route::post('/notes/{noteId}/share/{courseId}', [NoteController::class, 'shareNoteWithCourse']);

        // TESTS (sekcja Quiz)
        Route::get('/tests',                   [TestController::class, 'indexForUser']);
        Route::post('/tests',                  [TestController::class, 'storeForUser']);
        Route::get('/tests/{id}',              [TestController::class, 'showForUser']);
        Route::put('/tests/{id}',              [TestController::class, 'updateForUser']);
        Route::delete('/tests/{id}',           [TestController::class, 'destroyForUser']);
        Route::get('/tests/{testId}/questions',[TestController::class, 'questionsForUser']);
        Route::post('/tests/{testId}/questions',[TestController::class, 'storeQuestion']);
        Route::put('/tests/{testId}/questions/{questionId}', [TestController::class, 'updateQuestion']);
        Route::delete('/tests/{testId}/questions/{questionId}', [TestController::class, 'destroyQuestion']);
        Route::get('/tests/{testId}/questions/{questionId}/answers', [TestController::class, 'getAnswersForQuestion']);
        Route::post('/tests/{testId}/questions/{questionId}/answers', [TestController::class, 'storeAnswer']);
        Route::put('/tests/{testId}/questions/{questionId}/answers/{answerId}', [TestController::class, 'updateAnswer']);
        Route::delete('/tests/{testId}/questions/{questionId}/answers/{answerId}', [TestController::class, 'destroyAnswer']);
        Route::post('/tests/{testId}/share',   [TestController::class, 'shareTestWithCourse']);

    });
// COURSES – akcje poza /me
    Route::post('/courses/{courseId}/invite-user', [InvitationController::class, 'inviteUser']);
    Route::post('/courses/{courseId}/remove-user', [CourseController::class, 'removeUser']);

    // INVITATIONS
    Route::get('/me/invitations-received', [InvitationController::class, 'invitationsReceived']);
    Route::get('/me/invitations-sent',     [InvitationController::class, 'invitationsSent']);
    Route::post('/invitations/{token}/accept', [InvitationController::class, 'acceptInvitation']);
    Route::post('/invitations/{token}/reject', [InvitationController::class, 'rejectInvitation']);

    // (zostawiamy też routy dla tests in courses, jeśli używasz)
    Route::get('/courses/{courseId}/tests',                 [TestController::class, 'indexForCourse']);
    Route::post('/courses/{courseId}/tests',                [TestController::class, 'storeForCourse']);
    Route::get('/courses/{courseId}/tests/{testId}',        [TestController::class, 'showForCourse']);
    Route::put('/courses/{courseId}/tests/{testId}',        [TestController::class, 'updateForCourse']);
    Route::delete('/courses/{courseId}/tests/{testId}',     [TestController::class, 'destroyForCourse']);

});
