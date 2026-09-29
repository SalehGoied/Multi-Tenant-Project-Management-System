<?php

use App\Http\Controllers\Auth\OtpController;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/otp/request', [OtpController::class, 'request'])
    ->middleware('throttle:otp-request');

Route::post('/otp/verify', [OtpController::class, 'verify']);

Route::get('/user', function (Request $request) {
    return new UserResource($request->user());
})->middleware(['auth:sanctum', 'resolve.company']);
