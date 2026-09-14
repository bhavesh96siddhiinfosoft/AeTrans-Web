<?php

namespace App\Providers;

use App\Services\Firebase\CustomerAccounts;
use App\Services\Firebase\FirebaseSession;
use App\Services\Firebase\Firestore;
use App\Services\Firebase\FirestoreDocuments;
use App\Services\Firebase\FirestoreWriter;
use App\Services\Firebase\IdentityToolkit;
use App\Services\Firebase\ServiceAccount;
use App\Services\Booking\BookingDraft;
use App\Services\Booking\BookingReservation;
use App\Services\Booking\Coupons;
use App\Services\Booking\SeatAvailability;
use App\Services\Booking\VehicleAvailability;
use App\Services\Mail\CustomerMail;
use App\Services\Site\Catalog;
use App\Services\Site\SiteServices;
use App\Services\Site\CmsPages;
use App\Services\Site\Currency;
use App\Services\Site\Languages;
use App\Services\Site\SiteSettings;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * The Firebase services are singletons built from config rather than resolved
     * field by field in each controller: the API key and project id are read in one
     * place, so a missing .env value fails here, loudly, instead of arriving at
     * Google as `?key=` and coming back as a puzzling 400.
     */
    public function register(): void
    {
        $this->app->singleton(IdentityToolkit::class, function () {
            return new IdentityToolkit(
                apiKey: $this->required('firebase.web.apiKey', 'FIREBASE_APIKEY'),
                timeout: (int) config('firebase.timeout', 10),
            );
        });

        $this->app->singleton(FirestoreDocuments::class, function () {
            return new FirestoreDocuments(
                projectId: $this->required('firebase.web.projectId', 'FIREBASE_PROJECT_ID'),
                timeout: (int) config('firebase.timeout', 10),
            );
        });

        $this->app->singleton(CustomerAccounts::class, function ($app) {
            return new CustomerAccounts(
                firestore: $app->make(FirestoreDocuments::class),
                collection: (string) config('firebase.profile.collection', 'users'),
                writeProfile: (bool) config('firebase.profile.sync', false),
                blockCheckFailsClosed: (bool) config('firebase.profile.block_check_strict', false),
            );
        });

        // Not a singleton: it wraps the request's session, and a singleton would hold
        // the first request's session for the life of the worker.
        $this->app->bind(FirebaseSession::class, function ($app) {
            return new FirebaseSession(
                session: $app['session.store'],
                auth: $app->make(IdentityToolkit::class),
            );
        });

        $this->app->singleton(ServiceAccount::class, function () {
            return new ServiceAccount(config('firebase.credentials'));
        });

        $this->app->singleton(Firestore::class, function ($app) {
            return new Firestore(
                projectId: $this->required('firebase.web.projectId', 'FIREBASE_PROJECT_ID'),
                serviceAccount: $app->make(ServiceAccount::class),
                timeout: (int) config('firebase.timeout', 10),
            );
        });

        $this->app->singleton(Languages::class, function ($app) {
            return new Languages(
                firestore: $app->make(Firestore::class),
                cacheSeconds: (int) config('firebase.settings_cache_seconds', 300),
            );
        });

        foreach ([Catalog::class, Currency::class, CmsPages::class] as $service) {
            $this->app->singleton($service, function ($app) use ($service) {
                return new $service(
                    firestore: $app->make(Firestore::class),
                    cacheSeconds: (int) config('firebase.settings_cache_seconds', 300),
                );
            });
        }

        /*
         * Memoised per request, so the header, the footer and a booking summary on the
         * same page resolve the admin's service names once rather than three times.
         */
        $this->app->singleton(SiteServices::class, function ($app) {
            return new SiteServices($app->make(Catalog::class));
        });

        $this->app->singleton(Coupons::class, function ($app) {
            return new Coupons(
                firestore: $app->make(Firestore::class),
                cacheSeconds: (int) config('firebase.settings_cache_seconds', 300),
            );
        });

        $this->app->singleton(SeatAvailability::class, function ($app) {
            return new SeatAvailability($app->make(Firestore::class));
        });

        $this->app->singleton(VehicleAvailability::class, function ($app) {
            return new VehicleAvailability($app->make(Firestore::class), $app->make(Catalog::class));
        });

        $this->app->singleton(FirestoreWriter::class, function () {
            return new FirestoreWriter(
                projectId: $this->required('firebase.web.projectId', 'FIREBASE_PROJECT_ID'),
                timeout: (int) config('firebase.timeout', 10) + 5,
            );
        });

        // Bound, not shared: it reaches the request's Firebase session for the token.
        $this->app->bind(BookingReservation::class, function ($app) {
            return new BookingReservation(
                writer: $app->make(FirestoreWriter::class),
                firebase: $app->make(FirebaseSession::class),
                availability: $app->make(VehicleAvailability::class),
                catalog: $app->make(Catalog::class),
                currency: $app->make(Currency::class),
                mail: $app->make(CustomerMail::class),
            );
        });

        // Bound, not shared: it wraps THIS request's session.
        $this->app->bind(BookingDraft::class, function ($app) {
            return new BookingDraft($app['session.store']);
        });

        $this->app->singleton(SiteSettings::class, function ($app) {
            return new SiteSettings(
                firestore: $app->make(Firestore::class),
                cacheSeconds: (int) config('firebase.settings_cache_seconds', 300),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Global Settings reach the shell through a view composer rather than
         * `View::share`, so the Firestore read happens only for a request that actually
         * draws a page — never for a form POST that redirects, and never in a console
         * command.
         *
         * `layouts.*` is every shell the site has (public, guest, app), so a new one
         * gets the header and footer without anyone remembering to wire it up.
         */
        View::composer(['layouts.*', 'partials.*', 'auth.partials.*', 'booking.*', 'home'], function ($view) {
            $view->with('site', $this->app->make(SiteSettings::class));
            $view->with('languages', $this->app->make(Languages::class));
            $view->with('money', $this->app->make(Currency::class));
            /*
             * The footer lists the admin's CMS pages, so every shell needs them. Read
             * through the same composer as the rest, which means a POST that only
             * redirects never pays for the lookup.
             */
            $view->with('cmsPages', $this->app->make(CmsPages::class));
            /*
             * What the admin calls each service. In the shell because the header and
             * the footer both link to them by name, and on `home` because its two
             * service cards are the first place a customer reads either name.
             */
            $view->with('services', $this->app->make(SiteServices::class));
        });
    }

    private function required(string $key, string $env): string
    {
        $value = (string) config($key);

        if ($value === '') {
            throw new \RuntimeException("Firebase is not configured: {$env} is missing from .env.");
        }

        return $value;
    }
}
