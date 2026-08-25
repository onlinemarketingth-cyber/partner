<?php

namespace App\Services\Payment\Gateways;

use RuntimeException;

/**
 * The provider said no, or could not be reached.
 *
 * Its message is shown to an ADMIN configuring a gateway, never to a paying
 * customer — a customer has no use for "invalid secret key" and a provider's
 * raw error can echo back fragments of what was sent.
 */
class GatewayException extends RuntimeException {}
