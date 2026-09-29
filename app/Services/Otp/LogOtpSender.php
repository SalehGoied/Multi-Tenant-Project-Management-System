<?php

namespace App\Services\Otp;

use App\Contracts\OtpSender;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Writes the one-time code to the application log so it can be read locally.
 */
class LogOtpSender implements OtpSender
{
    public function send(User $user, string $code): void
    {
        Log::info('OTP code for '.$user->phone.': '.$code);
    }
}
