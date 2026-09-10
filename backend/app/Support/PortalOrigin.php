<?php

namespace App\Support;

use App\Models\Order;

/**
 * 2026-09-10 — WHICH portal address a customer's link should point at.
 *
 * ── THE PROBLEM ──
 *
 * The agent portal is served from more than one first-party domain: the
 * canonical FRONTEND_URL, plus the parked aliases listed in
 * CORS_EXTRA_ORIGINS (apps.liveto100club.com today). Every public URL in this
 * system was built from FRONTEND_URL alone, so a customer who bought on the
 * alias — read the page there, typed their details there, paid there — was
 * sent to a DIFFERENT brand's domain by the confirmation email and by the
 * gateway's return URL. To that customer the receipt arrives from a site they
 * have never seen, which reads as a phishing attempt and gets deleted.
 *
 * ── WHY AN ALLOWLIST AND NOT "just trust the Origin header" ──
 *
 * The Origin on a public, unauthenticated checkout is attacker-controlled. If
 * it were written through as-is, anyone could make this system send a customer
 * an email from our address containing a link to their own domain, styled as
 * our payment page — a phishing kit we host and sign. So an origin is used
 * ONLY if it is one this deployment already declares as its own, in exactly
 * the same list CORS enforces. Anything else falls back to the canonical host,
 * which is the behaviour every order had before this class existed.
 *
 * ── ONE SOURCE FOR THE PAY URL ──
 *
 * `payUrl()` is the single derivation. OrderResource, the Stripe return URL
 * and the confirmation email all call it rather than each re-joining a config
 * value to a token, because the day one of them disagrees is the day a
 * customer is sent somewhere their order does not exist.
 */
final class PortalOrigin
{
    /** The one canonical portal host — what every link fell back to before. */
    public static function canonical(): string
    {
        return rtrim((string) config('services.agent_portal.frontend_url'), '/');
    }

    /**
     * Every origin this deployment serves the portal from, normalized.
     *
     * Read from the SAME two settings config/cors.php builds its allowed
     * origins from. Deliberately not a third list: a domain that may not make
     * a credentialed request to this API is not a domain we should be sending
     * customers to either, and two lists would eventually disagree.
     *
     * @return list<string>
     */
    public static function allowed(): array
    {
        // config, not env(): once `config:cache` has run the .env file is not
        // loaded at all and env() answers null — which would silently shrink
        // this allowlist to one entry in production and nowhere else.
        $extra = array_map('trim', explode(',', (string) config('services.agent_portal.extra_origins', '')));

        $origins = array_map(
            fn (string $value) => self::normalize($value),
            array_merge([self::canonical()], $extra),
        );

        return array_values(array_unique(array_filter($origins)));
    }

    /**
     * The origin to remember for this checkout, or null to use the canonical.
     *
     * Null is the safe answer and the common one: an order created by an agent
     * in the console has no customer-facing origin to preserve.
     */
    public static function resolve(?string $origin): ?string
    {
        $normalized = self::normalize((string) $origin);

        if ($normalized === null || $normalized === self::normalize(self::canonical())) {
            // The canonical host is stored as null rather than as itself, so a
            // deployment that MOVES domain moves every old order's links with
            // it. Only a deliberate alias is pinned.
            return null;
        }

        return in_array($normalized, self::allowed(), true) ? $normalized : null;
    }

    /**
     * The customer-facing /pay/{token} URL for an order — on the domain that
     * customer actually bought from.
     */
    public static function payUrl(Order $order): string
    {
        $base = self::normalize((string) $order->checkout_origin) ?? self::canonical();

        return rtrim($base, '/').'/pay/'.$order->public_token;
    }

    /**
     * scheme://host[:port], lowercased, with any path/query discarded — or
     * null if the value is not a usable absolute origin.
     *
     * A path is dropped rather than kept because comparing "the same origin
     * with and without a trailing segment" as different strings is how an
     * allowlist quietly stops matching.
     */
    private static function normalize(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $parts = parse_url($value);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $origin = $scheme.'://'.strtolower((string) $parts['host']);

        return isset($parts['port']) ? $origin.':'.$parts['port'] : $origin;
    }
}
