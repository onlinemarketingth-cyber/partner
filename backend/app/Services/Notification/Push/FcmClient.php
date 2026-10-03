<?php

namespace App\Services\Notification\Push;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 2026-10-02 — MOB-11. Sends ONE message to ONE token through Firebase Cloud
 * Messaging HTTP v1, for iOS and Android alike.
 *
 * ── WHY NO SDK ──
 *
 * FCM v1 needs two HTTP calls: trade a signed JWT for an OAuth2 access token
 * (Google's service-account "JWT bearer" flow), then POST the message with
 * it. That is openssl_sign() plus Laravel's Http client — both already here.
 * The SDKs that do this (google/auth, kreait/firebase-php) would add a tree
 * of dependencies to save about sixty lines, so this class is those sixty
 * lines.
 *
 * ── WHEN IT IS OFF ──
 *
 * isConfigured() is false when services.firebase.project_id or .credentials
 * is empty or the credentials file cannot be read. Push is then simply not
 * attempted; the reason is logged once per process at info level (a dev
 * machine or a staging box without Firebase is a normal state, not an
 * incident). A credentials file that exists but is broken is logged at
 * warning when a send is first tried — that one IS somebody's mistake.
 *
 * ── THE ACCESS TOKEN ──
 *
 * Google's tokens live an hour. One is cached for at most 50 minutes (less
 * if Google says it expires sooner), keyed by the service account, so a
 * rotated credentials file is picked up without a cache flush. A 401 from
 * FCM drops the cached token and retries that one message once.
 */
class FcmClient
{
    public const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    public const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const SEND_URL = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

    private const ACCESS_TOKEN_MAX_TTL_SECONDS = 3000;

    private const HTTP_TIMEOUT_SECONDS = 10;

    private static bool $loggedDisabled = false;

    public function isConfigured(): bool
    {
        $projectId = (string) config('services.firebase.project_id');
        $path = (string) config('services.firebase.credentials');

        if ($projectId === '' || $path === '' || ! is_readable($path)) {
            if (! self::$loggedDisabled) {
                self::$loggedDisabled = true;
                Log::info('Push notifications are disabled: FIREBASE_PROJECT_ID / FIREBASE_CREDENTIALS not set or the credentials file is unreadable.');
            }

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $message  FCM v1 `message` object, including `token`.
     */
    public function send(array $message): FcmSendOutcome
    {
        try {
            $response = $this->post($message, forceFreshToken: false);

            if ($response?->status() === 401) {
                $response = $this->post($message, forceFreshToken: true);
            }
        } catch (Throwable $e) {
            Log::warning('FCM send failed before a response: '.$e->getMessage());

            return FcmSendOutcome::Failed;
        }

        if ($response === null) {
            return FcmSendOutcome::Failed;
        }

        if ($response->successful()) {
            return FcmSendOutcome::Sent;
        }

        if ($this->isDeadToken($response)) {
            return FcmSendOutcome::TokenGone;
        }

        Log::warning('FCM send rejected', [
            'status' => $response->status(),
            'error' => $response->json('error.status'),
            'message' => $response->json('error.message'),
        ]);

        return FcmSendOutcome::Failed;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function post(array $message, bool $forceFreshToken): ?Response
    {
        $accessToken = $this->accessToken($forceFreshToken);

        if ($accessToken === null) {
            return null;
        }

        $url = sprintf(self::SEND_URL, rawurlencode((string) config('services.firebase.project_id')));

        return Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(self::HTTP_TIMEOUT_SECONDS)
            ->post($url, ['message' => $message]);
    }

    /**
     * Is this the token's fault, permanently?
     *
     * UNREGISTERED (404) — the app was uninstalled or the token rotated.
     * Always the token.
     *
     * INVALID_ARGUMENT (400) — the owner asked for these to be deleted too,
     * and they should be WHEN THE TOKEN IS WHAT IS INVALID. But FCM answers
     * the same status for a malformed MESSAGE, and a payload bug of ours
     * would then delete every device on the platform one notification at a
     * time. So an INVALID_ARGUMENT only counts when FCM points at the token:
     * a `message.token` field violation, or a message that names the
     * registration token. Anything else is logged and the token kept.
     */
    private function isDeadToken(Response $response): bool
    {
        $error = $response->json('error');

        if (! is_array($error)) {
            return false;
        }

        $details = is_array($error['details'] ?? null) ? $error['details'] : [];
        $errorCodes = [];
        $tokenFieldViolated = false;

        foreach ($details as $detail) {
            if (! is_array($detail)) {
                continue;
            }

            if (isset($detail['errorCode']) && is_string($detail['errorCode'])) {
                $errorCodes[] = $detail['errorCode'];
            }

            foreach ((array) ($detail['fieldViolations'] ?? []) as $violation) {
                if (is_array($violation) && ($violation['field'] ?? null) === 'message.token') {
                    $tokenFieldViolated = true;
                }
            }
        }

        if (in_array('UNREGISTERED', $errorCodes, true)) {
            return true;
        }

        $isInvalidArgument = ($error['status'] ?? null) === 'INVALID_ARGUMENT'
            || in_array('INVALID_ARGUMENT', $errorCodes, true);

        if (! $isInvalidArgument) {
            return false;
        }

        return $tokenFieldViolated
            || str_contains(strtolower((string) ($error['message'] ?? '')), 'registration token');
    }

    private function accessToken(bool $forceFresh): ?string
    {
        $credentials = $this->credentials();

        if ($credentials === null) {
            return null;
        }

        $cacheKey = 'fcm.access_token.'.sha1($credentials['client_email']);

        if (! $forceFresh) {
            $cached = Cache::get($cacheKey);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        Cache::forget($cacheKey);

        $assertion = $this->signedAssertion($credentials['client_email'], $credentials['private_key']);

        if ($assertion === null) {
            return null;
        }

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(self::HTTP_TIMEOUT_SECONDS)
            ->post(self::TOKEN_URI, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            Log::warning('FCM: could not obtain an access token', [
                'status' => $response->status(),
                'error' => $response->json('error'),
            ]);

            return null;
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 3600);
        $ttl = max(60, min(self::ACCESS_TOKEN_MAX_TTL_SECONDS, $expiresIn - 600));

        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }

    /**
     * @return array{client_email: string, private_key: string}|null
     */
    private function credentials(): ?array
    {
        $path = (string) config('services.firebase.credentials');
        $raw = @file_get_contents($path);
        $json = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($json)
            || ! is_string($json['client_email'] ?? null)
            || ! is_string($json['private_key'] ?? null)) {
            Log::warning('FCM: the credentials file is not a service-account JSON with client_email and private_key.');

            return null;
        }

        return ['client_email' => $json['client_email'], 'private_key' => $json['private_key']];
    }

    private function signedAssertion(string $clientEmail, string $privateKey): ?string
    {
        $now = time();

        $segments = [
            $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64Url((string) json_encode([
                'iss' => $clientEmail,
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URI,
                'iat' => $now,
                'exp' => $now + 3600,
            ])),
        ];

        $key = openssl_pkey_get_private($privateKey);
        $signature = '';

        if ($key === false || ! openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
            Log::warning('FCM: could not sign the service-account assertion (bad private_key?).');

            return null;
        }

        $segments[] = $this->base64Url($signature);

        return implode('.', $segments);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
