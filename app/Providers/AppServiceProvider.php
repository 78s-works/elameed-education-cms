<?php

namespace App\Providers;

use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Engagement\Models\Comment;
use App\Modules\Engagement\Models\SupportTicket;
use App\Modules\Engagement\Models\TicketReply;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Files\DocumentPolicy;
use App\Support\Files\Models\Document;
use App\Support\Files\Observers\DocumentsObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /** Models that hold Pattern B documents (see HasDocuments). */
    private const DOCUMENT_OWNERS = [
        Comment::class,
        SupportTicket::class,
        TicketReply::class,
        ExamAttempt::class,
        Lesson::class,
        Tenant::class,
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiters();

        // Every stored file is authorized through one policy, keyed off the
        // document's purpose. See App\Support\Files\DocumentPolicy.
        Gate::policy(Document::class, DocumentPolicy::class);

        // Deleting an owner takes its files with it — blob included. A FK cascade
        // would drop the row and strand the blob, which is the leak this whole
        // change exists to close.
        foreach (self::DOCUMENT_OWNERS as $owner) {
            $owner::observe(DocumentsObserver::class);
        }
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
