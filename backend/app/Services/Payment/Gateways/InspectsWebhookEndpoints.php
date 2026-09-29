<?php

namespace App\Services\Payment\Gateways;

/**
 * A provider whose API can say how its webhook endpoints are configured.
 *
 * Optional, because not every provider offers it: Stripe lists them
 * (GET /v1/webhook_endpoints); Omise has no such API, so an Omise account is
 * judged only by what actually arrives (WebhookHealthService).
 */
interface InspectsWebhookEndpoints
{
    /**
     * Every endpoint the account has, as {url, enabled, events}.
     *
     * The signing secret is NOT among them — Stripe returns it only when an
     * endpoint is created, so whether it matches ours can only be inferred
     * from refused deliveries.
     *
     * @param  array<string, string>  $credentials
     * @return list<array{url: string, enabled: bool, events: list<string>}>
     *
     * @throws GatewayException when the provider cannot be asked
     */
    public function webhookEndpoints(array $credentials): array;

    /**
     * The event types this application needs the endpoint to send.
     *
     * @return list<string>
     */
    public function requiredWebhookEvents(): array;
}
