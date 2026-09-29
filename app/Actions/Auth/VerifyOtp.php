<?php

namespace App\Actions\Auth;

use App\Models\OtpCode;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

class VerifyOtp
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private CurrentCompany $currentCompany) {}

    /**
     * Exchange a one-time code for a personal access token.
     *
     * Unknown phones, missing codes, wrong codes, and expired codes share one error.
     */
    public function handle(string $phone, string $code): NewAccessToken
    {
        $user = User::query()
            ->where('phone', PhoneNumber::normalize($phone))
            ->first();

        if ($user === null || $user->company === null) {
            $this->invalid();
        }

        $this->currentCompany->set($user->company);

        $accessToken = DB::transaction(function () use ($user, $code): ?NewAccessToken {
            $otp = OtpCode::query()
                ->whereBelongsTo($user)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($otp === null || ! Hash::check($code, $otp->code_hash)) {
                if ($otp !== null) {
                    $this->recordFailedAttempt($otp);
                }

                return null;
            }

            $otp->forceFill(['consumed_at' => now()])->save();

            $user->loadMissing('company');

            $token = $user->createToken('otp');
            $token->accessToken->setRelation('tokenable', $user);

            return $token;
        });

        if (! $accessToken instanceof NewAccessToken) {
            $this->invalid();
        }

        return $accessToken;
    }

    /**
     * Count a miss. The fifth miss consumes the code so the rest of the TTL cannot be guessed.
     */
    private function recordFailedAttempt(OtpCode $otp): void
    {
        $attempts = $otp->attempts + 1;

        $otp->forceFill([
            'attempts' => $attempts,
            'consumed_at' => $attempts >= self::MAX_ATTEMPTS ? now() : null,
        ])->save();
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages([
            'code' => 'The verification code is invalid.',
        ]);
    }
}
