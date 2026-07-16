<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiters();
    }

    private function configureRateLimiters(): void
    {
        // OTP send / password-reset request: throttle per identifier (phone/email)
        // AND per IP, so neither a victim's phone nor a single IP can be spammed
        // (06_Engineering_Guide.md §8 — "OTP throttled per phone").
        RateLimiter::for('otp', fn (Request $request) => [
            Limit::perMinute(5)->by('otp:'.$request->input('identifier', '').'|'.$request->ip()),
            Limit::perMinute(15)->by('otp-ip:'.$request->ip()),
        ]);

        // Login / OTP verify: throttle per IP.
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(10)->by('auth:'.$request->ip()),
        ]);

        // Public, unauthenticated read endpoints (tenant context + landing):
        // throttle per IP so a valid host can't be hammered/scraped. The limit is
        // tunable via TENANCY_PUBLIC_RATE_LIMIT (config/tenancy.php).
        RateLimiter::for('public', fn (Request $request) => [
            Limit::perMinute((int) config('tenancy.public_rate_limit', 120))->by('public:'.$request->ip()),
        ]);
    }
}
