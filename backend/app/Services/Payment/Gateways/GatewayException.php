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
class GatewayException extends RuntimeException
{
    /**
     * The credential field this rejection is about, when it is about one.
     *
     * 2026-09-03 — a human pasted the webhook signing secret into the
     * publishable-key box. The message said exactly what was wrong, at the
     * bottom of a form with three boxes in it, and no box was marked. The
     * admin screen already highlights the offending input when the error
     * arrives keyed on a field; a provider's own rejection arrived keyed on
     * "credentials", so the one class of error most likely to BE about a
     * specific box was the one that never marked one.
     */
    public function __construct(string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}
