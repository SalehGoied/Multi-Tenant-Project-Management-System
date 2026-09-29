<?php

use App\Http\Controllers\Auth\OtpController;
use App\Http\Controllers\ProjectController;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/otp/request', [OtpController::class, 'request'])
    ->middleware('throttle:otp-request');

Route::post('/otp/verify', [OtpController::class, 'verify']);

Route::middleware(['auth:sanctum', 'resolve.company'])->group(function (): void {
    Route::get('/user', function (Request $request): JsonResponse {
        return ApiResponse::success(new UserResource($request->user()), 'Authenticated user retrieved.');
    });

    Route::apiResource('projects', ProjectController::class);

    Route::post('/projects/{project}/users', [ProjectController::class, 'assign'])
        ->name('projects.users.store');

    Route::delete('/projects/{project}/users/{user}', [ProjectController::class, 'unassign'])
        ->whereNumber('user')
        ->name('projects.users.destroy');
});
