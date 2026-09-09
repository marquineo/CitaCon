<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SlotController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AuthController;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/slots', [SlotController::class, 'index']);
    Route::get('/slots/{slot}', [SlotController::class, 'show']);
    Route::post('/slots', [SlotController::class, 'store']);
    Route::put('/slots/{slot}', [SlotController::class, 'update']);
    Route::delete('/slots/{slot}', [SlotController::class, 'destroy']);
    Route::patch('/slots/{slot}/block', [SlotController::class, 'block']);
    Route::patch('/slots/{slot}/unblock', [SlotController::class, 'unblock']);

    Route::get('/reservations', [ReservationController::class, 'index']);
    Route::post('/reservations', [ReservationController::class, 'store']);
    Route::get('/reservations/{reservation}', [ReservationController::class, 'show']);
    Route::delete('/reservations/{reservation}', [ReservationController::class, 'destroy']);

    Route::get('/users/me/quota', [UserController::class, 'quota']);
    Route::patch('/users/{user}/weekly-hours', [UserController::class, 'updateWeeklyHours']);
});
