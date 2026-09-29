<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RequestOtp;
use App\Actions\Auth\VerifyOtp;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RequestOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\AuthTokenResource;
use Illuminate\Http\JsonResponse;

class OtpController extends Controller
{
    public function request(RequestOtpRequest $request, RequestOtp $requestOtp): JsonResponse
    {
        $requestOtp->handle((string) $request->validated('phone'));

        return response()->json([
            'message' => 'If an account exists for that phone number, a verification code has been sent.',
        ], JsonResponse::HTTP_ACCEPTED);
    }

    public function verify(VerifyOtpRequest $request, VerifyOtp $verifyOtp): AuthTokenResource
    {
        $token = $verifyOtp->handle(
            (string) $request->validated('phone'),
            (string) $request->validated('code'),
        );

        return new AuthTokenResource($token);
    }
}
