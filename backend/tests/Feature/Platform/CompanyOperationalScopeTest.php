<?php

namespace Tests\Feature\Platform;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Company::scopeOperational() — the SQL twin of isOperational(), used by
 * every scheduled job to skip closed companies (2026-09-26).
 */
class CompanyOperationalScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_agrees_with_is_operational_row_for_row(): void
    {
        $open = Company::factory()->create();
        $closed = Company::factory()->create(['is_active' => false]);
        $deleted = Company::factory()->create();
        $deleted->delete();

        $ids = Company::operational()->pluck('id')->all();
        $this->assertSame([$open->id], $ids);

        foreach (Company::withTrashed()->get() as $company) {
            $this->assertSame($company->isOperational(), in_array($company->id, $ids, true), $company->name);
        }
        $this->assertFalse($closed->fresh()->isOperational());
    }

    public function test_dropping_the_global_scopes_does_not_drop_half_of_it(): void
    {
        // A job that reaches for withoutGlobalScopes() — as most of them do
        // for their own models — must not start seeing deleted companies.
        $deleted = Company::factory()->create();
        $deleted->delete();

        $this->assertSame([], Company::withoutGlobalScopes()->operational()->pluck('id')->all());
    }
}
