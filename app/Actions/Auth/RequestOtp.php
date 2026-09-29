<?php

namespace App\Actions\Auth;

use App\Contracts\OtpSender;
use App\Models\OtpCode;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RequestOtp
{
    private const CODE_LENGTH = 6;

    public function __construct(
        private OtpSender $otpSender,
        private CurrentCompany $currentCompany,
    ) {}

    /**
     * Issue a code when the phone matches a user. An unknown phone writes nothing.
     */
    public function handle(string $phone): void
    {
        $user = User::query()
            ->where('phone', PhoneNumber::normalize($phone))
            ->first();

        if ($user === null) {
            return;
        }

        $company = $user->company;

        if ($company === null) {
            return;
        }

        $this->currentCompany->set($company);

        $plaintext = $this->generateCode();

        DB::transaction(function () use ($user, $plaintext): void {
            OtpCode::query()
                ->whereBelongsTo($user)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            OtpCode::query()->create([
                'user_id' => $user->id,
                'code_hash' => Hash::make($plaintext),
                'expires_at' => now()->addMinutes((int) config('otp.ttl', 5)),
            ]);
        });

        $this->otpSender->send($user, $plaintext);
    }

    private function generateCode(): string
    {
        $ceiling = (int) (10 ** self::CODE_LENGTH) - 1;

        return str_pad((string) random_int(0, $ceiling), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }
}
