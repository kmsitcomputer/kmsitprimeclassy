<?php

namespace App\Providers;

use App\Models\User;
use App\Observers\UserObserver;
use App\Services\Geocode\NoopReverseGeocoder;
use App\Services\Geocode\NominatimReverseGeocoder;
use App\Services\Geocode\ReverseGeocoder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ReverseGeocoder::class, fn () => match (config('services.geocode.provider', 'nominatim')) {
            'nominatim' => new NominatimReverseGeocoder,
            default => new NoopReverseGeocoder,
        });
    }

    public function boot(): void
    {
        // Dedicated, independently-keyed limiters: an unnamed `throttle:N,M` shares ONE per-user bucket across every route that
        // uses it, so confirm calls would silently eat the request budget (and vice versa).
        \Illuminate\Support\Facades\RateLimiter::for('wa-number-request', fn (\Illuminate\Http\Request $r) => \Illuminate\Cache\RateLimiting\Limit::perMinutes(10, 6)->by('wa-number-request:'.($r->user()?->id ?? $r->ip())));
        \Illuminate\Support\Facades\RateLimiter::for('wa-number-confirm', fn (\Illuminate\Http\Request $r) => \Illuminate\Cache\RateLimiting\Limit::perMinutes(10, 12)->by('wa-number-confirm:'.($r->user()?->id ?? $r->ip())));

        // Email verification (self-service): dedicated buckets so confirm calls never eat the request budget.
        \Illuminate\Support\Facades\RateLimiter::for('email-verify-request', fn (\Illuminate\Http\Request $r) => \Illuminate\Cache\RateLimiting\Limit::perMinutes(10, 6)->by('email-verify-request:'.($r->user()?->id ?? $r->ip())));
        \Illuminate\Support\Facades\RateLimiter::for('email-verify-confirm', fn (\Illuminate\Http\Request $r) => \Illuminate\Cache\RateLimiting\Limit::perMinutes(10, 12)->by('email-verify-confirm:'.($r->user()?->id ?? $r->ip())));

        // Account recovery (unauthenticated, identifier-only): strict per-IP + per-identifier buckets (see service).
        \Illuminate\Support\Facades\RateLimiter::for('recovery-request', fn (\Illuminate\Http\Request $r) => [
            \Illuminate\Cache\RateLimiting\Limit::perMinutes(10, 6)->by('recovery-request:ip:'.$r->ip()),
            // F-5: ALSO per normalised identifier, keyed by a hash (never the raw email/phone in the cache key or logs). Applies to
            // every identifier identically, so the 429 says nothing about whether the account exists. Keys expire with the window.
            \Illuminate\Cache\RateLimiting\Limit::perMinutes(10, 3)->by('recovery-request:id:'.hash('sha256', mb_strtolower(trim((string) $r->input('identifier'))))),
        ]);
        \Illuminate\Support\Facades\RateLimiter::for('recovery-confirm', fn (\Illuminate\Http\Request $r) => \Illuminate\Cache\RateLimiting\Limit::perMinutes(10, 12)->by('recovery-confirm:'.$r->ip()));

        \Illuminate\Support\Facades\RateLimiter::for('agent-wa-device', fn (\Illuminate\Http\Request $r) => \Illuminate\Cache\RateLimiting\Limit::perMinute(12)->by('agent-wa-device:'.($r->user()?->id ?? $r->ip())));

        // Owner 2026-10-29: only a number the account proved through a `whatsapp_number` challenge counts as verified.
        $this->app->bindIf(
            \App\Services\WhatsApp\VerifiedWhatsAppNumberResolver::class,
            \App\Services\WhatsApp\VerifiedAccountNumberResolver::class,
        );

        // Dashboard SMTP (Super Admin) overrides the environment mailer only while it is switched ON and complete.
        $this->app->make(\App\Services\Mail\SmtpSettings::class)->applyRuntime();

        User::observe(UserObserver::class);

        // WhatsApp transactional notifications: inert unless the table exists, the gateway is enabled and the event is on.
        \App\Models\Order::observe(\App\Observers\WhatsAppLifecycleObserver::class);
        \App\Models\ReturnRequest::observe(\App\Observers\WhatsAppLifecycleObserver::class);
        \App\Models\RefundPayout::observe(\App\Observers\WhatsAppLifecycleObserver::class);
        \App\Models\RefundObligation::observe(\App\Observers\WhatsAppLifecycleObserver::class);
        \App\Models\BankTransferVerification::observe(\App\Observers\WhatsAppLifecycleObserver::class);

        // Operator in-app centre (delivery failures, gateway connection changes): inert until its table exists.
        \App\Models\WhatsAppMessage::observe(\App\Observers\AdminNotificationObserver::class);
        \App\Models\AgentWhatsAppDevice::observe(\App\Observers\AdminNotificationObserver::class);

        /**
         * "SUPERADMIN: dapat mengubah konfigurasi website, payment gateway,
         * shipping, CMS" — a single Gate rather than one boilerplate Policy
         * per settings/payment/shipping/CMS model, since all of them share
         * the exact same rule (super_admin only, no per-record ownership
         * nuance to speak of). Controllers for those modules call
         * `Gate::authorize('manage-system-config')` once built.
         */
        Gate::define('manage-system-config', fn (User $user) => $user->isRole('super_admin'));
    }
}
