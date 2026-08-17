<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Routing\Controller as BaseController;

// Laravel 11+ gotcha (still true in 12): the default skeleton's
// Controller no longer extends Illuminate\Routing\Controller, which is
// where the *instance* `$this->middleware()` helper lives.
// authorizeResource() (from AuthorizesRequests below) calls
// `$this->middleware("can:...")->only(...)` internally — without
// extending BaseController that throws "Call to undefined method
// ::middleware()" on every request, which is exactly what happened when
// `php artisan test --filter=Catalog` actually ran (structural review
// alone couldn't catch this — it's Laravel-internal runtime behavior,
// not a typo). Per the Laravel 11 upgrade guide: extend
// Illuminate\Routing\Controller to keep using the authorizeResource()
// pattern, rather than migrating every controller to explicit `can:`
// route middleware.
abstract class Controller extends BaseController
{
    use AuthorizesRequests;
}
