<?php

use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\TestController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\NoteController;
use Illuminate\Support\Facades\Route;

Route::post('/users/login', [UserController::class, 'login']);
Route::post('/users/register', [UserController::class, 'store']);

Route::middleware('auth:api')->group(function () {

    Route::prefix('me')->group(function () {

        // Profile
        Route::get('/profile', [UserController::class, 'show']);
        Route::get('/profile/avatar', [UserController::class, 'downloadAvatar']);
        Route::put('/profile', [UserController::class, 'update']);
        Route::delete('/profile', [UserController::class, 'destroy']);
        Route::post('/logout', [UserController::class, 'logout']);

        // Notes
        Route::get('/notes', [NoteController::class, 'index']);
        Route::get('/notes/{id}', [NoteController::class, 'download']);
        Route::post('/notes', [NoteController::class, 'store']);
        Route::put('/notes/{id}', [NoteController::class, 'update']);
        Route::delete('/notes/{id}', [NoteController::class, 'destroy']);

        Route::post('/notes/{noteId}/share/{courseId}', [NoteController::class, 'shareNoteWithCourse']);

        // Courses
        Route::get('/courses', [CourseController::class, 'index']);
        Route::get('/courses/{id}/avatar', [CourseController::class, 'downloadAvatar']);
        Route::post('/courses', [CourseController::class, 'store']);
        Route::delete('/courses/{id}', [CourseController::class, 'destroy']);
        Route::put('/courses/{id}', [CourseController::class, 'update']);

        // Tests (private)
        Route::get('/tests', [TestController::class, 'indexForUser']);
        Route::post('/tests', [TestController::class, 'storeForUser']);
        Route::post('/tests/{Id}/share', [TestController::class, 'shareTestWithCourse']);
        Route::get('/tests/{id}', [TestController::class, 'showForUser']);
        Route::put('/tests/{id}', [TestController::class, 'updateForUser']);
        Route::delete('/tests/{id}', [TestController::class, 'destroyForUser']);

        Route::post('/tests/{testId}/share/{courseId}', [TestController::class, 'shareTestWithCourse']);


        Route::post('/tests/{testId}/questions', [TestController::class, 'storeQuestion']);
        Route::get('/tests/{testId}/questions', [TestController::class, 'questionsForUser']);

        Route::put('/tests/{testId}/questions/{questionId}', [TestController::class, 'updateQuestion']);
        Route::delete('/tests/{testId}/questions/{questionId}', [TestController::class, 'destroyQuestion']);

        Route::post('/tests/{testId}/questions/{questionId}/answers', [TestController::class, 'storeAnswer']);
        Route::get('/tests/{testId}/questions/{questionId}/answers', [TestController::class, 'getAnswersForQuestion']);
        Route::put('/tests/{testId}/questions/{questionId}/answers/{answerId}', [TestController::class, 'updateAnswer']);
        Route::delete('/tests/{testId}/questions/{questionId}/answers/{answerId}', [TestController::class, 'destroyAnswer']);
    });

    Route::prefix('courses/{courseId}')->group(function () {
        // Course Invitations
        Route::post('/invite-user', [InvitationController::class, 'inviteUser']);
        Route::post('/remove-user', [InvitationController::class, 'removeUser']);

        // Posts (per course)
        Route::get('/posts', [PostController::class, 'index']);
        Route::post('/posts', [PostController::class, 'store']);
        Route::put('/posts/{id}', [PostController::class, 'update']);
        Route::delete('/posts/{id}', [PostController::class, 'destroy']);

        // Tests (public)
        Route::get('/tests', [TestController::class, 'indexForCourse']);
        Route::post('/tests', [TestController::class, 'storeForCourse']);
        Route::get('/tests/{testId}', [TestController::class, 'showForCourse']);
        Route::put('/tests/{testId}', [TestController::class, 'updateForCourse']);
        Route::delete('/tests/{testId}', [TestController::class, 'destroyForCourse']);
    });

    // Invitations
    Route::get('/me/invitations-sent', [InvitationController::class, 'invitationsSent']);
    Route::get('/me/invitations-received', [InvitationController::class, 'invitationsReceived']);
    Route::post('/invitations/{invitationId}/cancel', [InvitationController::class, 'cancelInvite']);
    Route::post('/invitations/{token}/accept', [InvitationController::class, 'acceptInvitation']);
    Route::post('/invitations/{token}/reject', [InvitationController::class, 'rejectInvitation']);

    // Admin Section
    Route::prefix('admin')->group(function () {
        // Users
        Route::get('/users', [AdminController::class, 'allUsers']);
        Route::get('/users/{id}', [AdminController::class, 'showUser']);
        Route::delete('/users/{id}', [AdminController::class, 'deleteUser']);
        Route::put('/users/{id}', [AdminController::class, 'updateUser']);

        // Notes
        Route::get('/notes', [AdminController::class, 'allNotes']);
        Route::get('/notes/{id}', [AdminController::class, 'downloadNote']);
        Route::delete('/notes/{id}', [AdminController::class, 'deleteNote']);
        Route::put('/notes/{id}', [AdminController::class, 'updateNote']);

        // Courses
        Route::get('/courses', [AdminController::class, 'allCourses']);
        Route::get('/{courseId}/users', [AdminController::class, 'showUsersForCourse']);
        Route::post('/{courseId}/users', [AdminController::class, 'addUserToCourse']);
        Route::delete('/{courseId}/users', [AdminController::class, 'removeUserFromCourse']);
        Route::put('/courses/{id}', [AdminController::class, 'updateCourse']);
        Route::delete('/courses/{id}', [AdminController::class, 'deleteCourse']);
    });
});
