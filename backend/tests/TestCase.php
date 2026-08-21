<?php

namespace Tests;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * A Company Admin who may confirm this company's payments.
     *
     * SECURITY AUDIT 2026-08-21 (human ruling D1). Confirming a payment used
     * to be something the order's own agent could do, so most tests that
     * needed a paid order simply acted as the agent they already had. That
     * is now forbidden — whoever earns the commission must not also be the
     * one attesting the money arrived — and roughly twenty tests across six
     * files needed the same new actor.
     *
     * Here rather than copied into each file so that the next test which
     * needs a confirmed payment cannot quietly reintroduce the agent by
     * writing its own helper. If who may confirm changes again, it changes
     * in one place and every test moves with it.
     */
    protected function paymentConfirmer(Company $company): User
    {
        return User::factory()->companyAdmin()->create(['company_id' => $company->id]);
    }
}
