<?php

namespace App\Enums;

// ADR-006 Round 3/4: companies.commission_plan_type — one plan type per
// company (human decision 2026-07-14). Unilevel is the existing/only
// working model (TASK-025's manager_id chain + commission_override_rules).
// Binary's schema is built alongside it now (human decision: "สร้างตาราง
// Binary ไว้เลย แต่ขึ้นกำลังพัฒนา") but has no working CommissionService
// logic yet — frontend-admin must show it as "อยู่ระหว่างพัฒนา" (under
// development) until that lands.
//
// ADR-011 (2026-07-22): human decision to cover all 4 agent-management
// standards simultaneously (Affiliate/Insurance/MLM/PRM) added the
// remaining 4 cases below. Each is schema/enum-only until its own task
// lands a working CommissionService engine — same "selectable but inert
// until built" pattern as Binary was between ADR-006 and TASK-029:
//   - Matrix              → TASK-030
//   - StairstepBreakaway  → TASK-031
//   - Generation          → TASK-031
//   - Affiliate           → TASK-032/033
// TASK-027 also adds products.commission_plan_type (nullable — inherits
// the company's value when null), so a plan type is now resolved per
// PRODUCT, not just per company. See Product::effectivePlanType().
enum CommissionPlanType: string
{
    case Unilevel = 'unilevel';
    case Binary = 'binary';
    case Matrix = 'matrix';
    case StairstepBreakaway = 'stairstep_breakaway';
    case Generation = 'generation';
    case Affiliate = 'affiliate';
}
