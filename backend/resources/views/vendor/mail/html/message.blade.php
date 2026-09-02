{{--
    Published from Illuminate\Mail so the header and footer can carry the
    TENANT'S brand instead of one global config('app.name').

    2026-09-02 — this platform is multi-tenant: a recruit signing up for
    "Thai Life insurance" was reading a mail headed with the platform's own
    product name, which is what a phishing attempt looks like.

    Both props are optional and fall back to the old behaviour, so a mail
    that does not know its company (the SMTP test, anything platform-level)
    renders exactly as before.

    Keep in step with the vendor copy when upgrading Laravel — this file is
    a fork of vendor/laravel/framework/src/Illuminate/Mail/resources/views.
--}}
@props(['brand' => null, 'brandUrl' => null])
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="$brandUrl ?? config('app.url')">
{{ $brand ?? config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ $brand ?? config('app.name') }}. {{ __('All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
