<?php

namespace App\Providers;

use App\Contracts\OtpSender;
use App\Services\Otp\LogOtpSender;
use App\Support\PhoneNumber;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bound in every environment. A real SMS vendor is a one-line change
        // of this binding, and that implementation must not log the plaintext code.
        $this->app->bind(OtpSender::class, LogOtpSender::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('otp-request', function (Request $request): Limit {
            // Keyed before any user lookup, so unknown phones are limited too.
            $phone = $request->input('phone');
            $normalized = is_string($phone) ? PhoneNumber::normalize($phone) : '';

            return Limit::perMinutes(10, 3)->by($normalized.'|'.(string) $request->ip());
        });
    }
}
