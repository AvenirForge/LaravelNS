<?php

use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\NoteController;
use Illuminate\Support\Facades\Route;

Route::post('/users/login', [UserController::class, 'login']);
Route::post('/users/register', [UserController::class, 'store']);
Route::middleware('auth:api')->group(function () {

    Route::prefix('me')->group(function () {
        Route::get('/profile', [UserController::class, 'show']);
        Route::put('/profile', [UserController::class, 'update']);
        Route::delete('/profile', [UserController::class, 'destroy']);
        Route::post('/logout', [UserController::class, 'logout']);

        Route::get('/notes', [NoteController::class, 'index']);
        Route::post('/notes', [NoteController::class, 'store']);
        Route::put('/notes/{id}', [NoteController::class, 'update']);
        Route::delete('/notes/{id}', [NoteController::class, 'destroy']);
    });

    Route::prefix('admin')->group(function () {
        Route::get('/users', [AdminController::class, 'allUsers']);
        Route::get('/users/{id}', [AdminController::class, 'showUser']);
        Route::delete('/users/{id}', [AdminController::class, 'deleteUser']);
        Route::put('/users/{id}', [AdminController::class, 'updateUser']);

        Route::get('/notes', [AdminController::class, 'allNotes']);
        Route::get('/notes/{id}', [AdminController::class, 'showNote']);
        Route::delete('/notes/{id}', [AdminController::class, 'deleteNote']);
        Route::put('/notes/{id}', [AdminController::class, 'updateNote']);
    });
});
