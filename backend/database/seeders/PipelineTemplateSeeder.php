<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Services\Pipeline\PipelineTemplateProvisioner;
use Illuminate\Database\Seeder;

/**
 * ADR-026 §3.1 (TASK-132) — the two SYSTEM pipeline templates, for every
 * company that already exists.
 *
 * The definitions and the write logic moved to
 * App\Services\Pipeline\PipelineTemplateProvisioner during ag-lead's
 * TASK-134a review: they were needed at RUNTIME too, because
 * CompanyService::create() must provision them for every company created
 * after the TASK-132 deploy (a seeder can only ever cover companies that
 * existed the last time someone ran it — a new tenant would otherwise have
 * had no templates at all, and the resolver fails closed).
 *
 * This seeder is now the "catch up existing companies" caller. It is
 * idempotent, so it stays safe to re-run at any time.
 */
class PipelineTemplateSeeder extends Seeder
{
    public function __construct(private PipelineTemplateProvisioner $provisioner) {}

    public function run(): void
    {
        Company::query()->each(function (Company $company): void {
            $this->provisioner->provision($company);
        });
    }
}
