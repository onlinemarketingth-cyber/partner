<?php

namespace App\Services\Payment;

use App\Enums\PaymentProvider;
use App\Services\Payment\Gateways\PaymentGateway;
use InvalidArgumentException;

/**
 * Provider key → driver.
 *
 * Thin on purpose. The value of this abstraction is that adding a gateway
 * touches one class and one config line; a registry that grew logic of its
 * own would become a second place where provider-specific behaviour hides,
 * which is precisely what the driver interface exists to prevent.
 */
class PaymentGatewayRegistry
{
    /** @return array<int, PaymentProvider> providers with a working driver */
    public function available(): array
    {
        return array_values(array_filter(
            PaymentProvider::cases(),
            fn (PaymentProvider $p) => isset(config('payments.gateways')[$p->value]),
        ));
    }

    /**
     * Resolved through the container, so a driver may declare its own
     * dependencies — ManualGateway needs PromptPayService.
     *
     * Throws rather than falling back to Manual for an unknown provider. A
     * silent fallback would mean a company whose config named a gateway that
     * no longer exists quietly starts collecting slip uploads instead, and
     * nobody would find out until somebody asked where the card payments
     * went.
     */
    public function driver(PaymentProvider $provider): PaymentGateway
    {
        $class = config('payments.gateways')[$provider->value] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException("No payment driver registered for [{$provider->value}].");
        }

        return app($class);
    }
}
