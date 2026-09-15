<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Server-Timing
    |--------------------------------------------------------------------------
    |
    | 2026-09-07 — when true, every response carries a Server-Timing header
    | with the request's total time, the time spent inside database queries and
    | how many there were (App\Http\Middleware\ServerTiming).
    |
    | Off by default and meant to be switched on only while investigating: a
    | duration is a side channel on the endpoints that work hardest to make two
    | outcomes indistinguishable (login, registration), and counting queries
    | costs a listener on every one of them.
    |
    */

    'server_timing' => (bool) env('SERVER_TIMING', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    /*
     * 2026-09-15 — 'th', and deliberately NOT read from the environment.
     *
     * Every message this application writes itself is already Thai. The ones
     * the FRAMEWORK writes were not, and they are exactly the ones a user
     * meets at the worst moment: a field left blank, a file too large, a date
     * the wrong way round. See lang/th/validation.php.
     *
     * ── WHY THE env() CALL WAS REMOVED RATHER THAN RE-DEFAULTED ──
     *
     * This line used to read env('APP_LOCALE', 'en') — and EVERY .env in
     * existence, here and in production, carries `APP_LOCALE=en`, because
     * that is what `laravel new` writes. Changing only the fallback would
     * have shipped this whole change dead: the code would say 'th', the
     * running site would stay English, and nothing would fail to say so. The
     * one manual step to fix that (editing .env over SSH) is exactly the step
     * that gets forgotten, and its failure mode is silent.
     *
     * That value was never a decision anybody made; it is scaffolding. So the
     * decision lives in code, where it is reviewable and cannot be undone by
     * an untouched file. APP_LOCALE has been dropped from .env.example for
     * the same reason: a key that looks like it works and does not is worse
     * than no key. The day this product needs a second language, the env read
     * comes back together with the language switch that justifies it.
     */
    'locale' => 'th',

    /*
     * Deliberately still 'en'. A key missing from lang/th falls back to
     * Laravel's own English sentence — which is imperfect and readable —
     * rather than rendering the raw key ("validation.required") on screen.
     */
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
