<?php

use App\Enums\PaymentProvider;
use App\Services\Payment\Gateways\ManualGateway;
use App\Services\Payment\Gateways\OmiseGateway;

return [

    /*
    |--------------------------------------------------------------------
    | The payment gateways this platform can talk to
    |--------------------------------------------------------------------
    |
    | Adding one is a driver class and a line here. Nothing in the
    | controllers, the pay page or the settings screen changes.
    |
    | Deliberately NOT pre-populated with 2C2P / GBPrimePay / Chillpay.
    | A provider listed with no working driver is a choice an admin can
    | select and then discover does nothing — worse than its absence,
    | because the absence is at least honest. They go in when a company
    | actually needs one.
    |
    | NO CREDENTIALS LIVE HERE. They are per company, encrypted, in
    | company_payment_gateway_settings — see that migration and ADR-027 §3
    | for why a platform-wide key pair was rejected.
    */
    'gateways' => [
        PaymentProvider::Manual->value => ManualGateway::class,
        PaymentProvider::Omise->value => OmiseGateway::class,
    ],

];
