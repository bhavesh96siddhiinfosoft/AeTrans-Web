<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * The locale cookie is left unencrypted. It holds `id` or `en` — nothing to
         * protect — and it has to be readable as plain text by the redirect that runs
         * before any page is rendered.
         */
        $middleware->encryptCookies(except: [
            \App\Http\Middleware\SetLocale::COOKIE,
        ]);

        /*
         * The panel's server-to-server call carries no cookie and no token, because
         * there is no browser on that side of it — it is authenticated by a shared
         * secret instead. See Internal\BookingStatusNotificationController.
         */
        $middleware->validateCsrfTokens(except: [
            'internal/*',
        ]);

        /*
         * Every page is answered in a language, so this runs on every web request
         * rather than being remembered route by route.
         */
        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
        ]);

        /*
         * `service:charter` / `service:airport-shuttle` on a booking route, so a flow
         * for a service the admin has switched off answers 404 rather than taking an
         * order nobody can fulfil.
         */
        $middleware->alias([
            'service' => \App\Http\Middleware\EnsureServiceIsOffered::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
