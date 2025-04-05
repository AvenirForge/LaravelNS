<?php

use App\Http\Controllers\GroupController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\PostController;

Route::post('/login', [UserController::class, 'login']);
//['email', 'password']

Route::post('/users', [UserController::class, 'store']);
//[ 'name', 'email', 'password', 'avatar',];

Route::middleware('auth:api')->group(function () {

    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
    Route::post('/logout', [UserController::class, 'logout']);

    Route::get('/users/{userId}/courses', [CourseController::class, 'index']);
    Route::post('/users/{userId}/courses', [CourseController::class, 'store']);
    Route::get('/users/{userId}/courses/{courseId}', [CourseController::class, 'show']);
    Route::put('/users/{userId}/courses/{courseId}', [CourseController::class, 'update']);
    Route::delete('/users/{userId}/courses/{courseId}', [CourseController::class, 'destroy']);

    Route::get('/users/{userId}/courses/{courseId}/notes', [NoteController::class, 'index']);
    Route::post('/users/{userId}/courses/{courseId}/notes', [NoteController::class, 'store']);
    Route::get('/users/{userId}/courses/{courseId}/notes/{noteId}', [NoteController::class, 'show']);
    Route::put('/users/{userId}/courses/{courseId}/notes/{noteId}', [NoteController::class, 'update']);
    Route::delete('/users/{userId}/courses/{courseId}/notes/{noteId}', [NoteController::class, 'destroy']);

// Grupy
    Route::post('/groups', [GroupController::class, 'store']); // Tworzenie grupy
    Route::post('/groups/{groupId}/users/{userId}', [GroupController::class, 'addUser']); // Dodawanie użytkownika
    Route::delete('/groups/{groupId}/users/{userId}', [GroupController::class, 'removeUser']); // Usuwanie użytkownika
    Route::get('/groups/{groupId}/users', [GroupController::class, 'showUsers']); // Lista użytkowników w grupie

// Posty w grupach
    Route::get('/groups/{groupId}/posts/{postId}', [PostController::class, 'show']); // Pobieranie posta
    Route::post('/groups/{groupId}/posts', [PostController::class, 'store']); // Tworzenie posta
    Route::put('/groups/{groupId}/posts/{postId}', [PostController::class, 'update']); // Aktualizacja posta
    Route::delete('/groups/{groupId}/posts/{postId}', [PostController::class, 'destroy']); // Usuwanie posta

});
