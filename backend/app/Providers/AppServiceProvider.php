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
        User::observe(UserObserver::class);

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
