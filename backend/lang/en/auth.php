<?php

// Standard Laravel auth language lines. Our minimal Laravel 12 skeleton
// doesn't ship a lang/ directory by default (no `lang:publish` run), but
// LoginRequest's ValidationException messages reference these keys —
// added directly rather than relying on an unpublished vendor default.
return [
    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',
];
