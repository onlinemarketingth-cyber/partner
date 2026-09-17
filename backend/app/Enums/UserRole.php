<?php

namespace App\Enums;

// The visibility levels of CLAUDE.md Section 5, rule 4.
// Not a permissions matrix — see TASK-001 "out of scope" note for why.
enum UserRole: string
{
    case Agent = 'agent';
    case CompanyAdmin = 'company_admin';
    case SuperAdmin = 'super_admin';

    /**
     * 2026-09-10 (human: "ที่ได้สิทธิ์ในการตัดได้เฉพาะหน้าการตัดสิทธิ์
     * เพราะทำงานคนละหน้าที่กัน") — THE FOURTH ROLE, and the first one added
     * since TASK-001.
     *
     * Front-desk staff: they redeem vouchers at a branch and do nothing else.
     * They are not a smaller Company Admin — they are a different job, and
     * the reason they need a role of their own is that every other way of
     * expressing them was worse:
     *
     *   · as an Agent, they cannot reach the admin console at all (the
     *     router logs an Agent straight back out);
     *   · as a Company Admin, they can read every sale, every commission
     *     figure and every customer's PDPA record in the company — to scan a
     *     code at a counter.
     *
     * A role says WHICH PART OF THE APP a person sees. What they may DO
     * inside it is the ability table plus their own grants
     * (PermissionResolver), which is why this role's row there holds exactly
     * one thing.
     *
     * BR-6 is unchanged: this user has a company_id like any other and every
     * scope reads it the same way.
     */
    case VoucherStaff = 'voucher_staff';

    /**
     * 2026-09-16 — THE FIFTH ROLE: a company that SUPPLIES products to us.
     *
     * Owner: "อีก User Role คือ Company Partner ที่จะนำสินค้าเข้ามาขายในระบบเรา
     * ได้ โดยสิทธิ์นี้จะเข้าได้แค่หน้า ตัดสิทธิ์บัตรกำนัล ซึ่งจะมีอีก 1 หน้าที่
     * สำหรับการ ดูว่าลูกค้าสั่งสินค้าอะไรของตนเองบ้าง".
     *
     * ── WHY THIS ONE IS DIFFERENT FROM EVERY ROLE ABOVE ──
     *
     * Agent, Company Admin, Voucher Staff and Super Admin all LOOK INWARD at
     * their own company. Their `company_id` is the answer to every scoping
     * question, and TenantScope applies it for free.
     *
     * A supplier looks the other way. The orders they care about belong to the
     * companies that SOLD their product — other tenants — and the only thing
     * tying those orders to them is `products.supplier_company_id`. Every
     * supplier-facing query therefore crosses TenantScope deliberately and
     * filters on that column instead. TenantScope is not a safety net here;
     * getting the filter right by hand IS the safety, the same way
     * VoucherRedemptionService already has to do it.
     *
     * That also means a mistake fails in an unusually bad direction: a missing
     * filter does not show a supplier too little, it shows them another
     * supplier's orders, including customers' names and delivery addresses.
     *
     * BR-6 still holds in the sense that matters — a supplier sees only rows
     * reachable from their own products — but it is no longer enforced by the
     * global scope, and every query in this area needs its own test.
     */
    case CompanyPartner = 'company_partner';

    public function label(): string
    {
        return match ($this) {
            self::Agent => 'Agent',
            self::CompanyAdmin => 'Company Admin',
            self::SuperAdmin => 'Super Admin',
            self::VoucherStaff => 'Voucher Staff',
            self::CompanyPartner => 'Company Partner',
        };
    }

    /**
     * Thai, for the screens people actually read. Kept beside label() rather
     * than in the UI layer because a role name is a thing this system names,
     * not a translation of a code identifier — and both admin apps would
     * otherwise carry their own copy.
     */
    public function labelTh(): string
    {
        return match ($this) {
            self::Agent => 'สมาชิก',
            self::CompanyAdmin => 'ผู้ดูแลบริษัท',
            self::SuperAdmin => 'ผู้ดูแลระบบ',
            self::VoucherStaff => 'พนักงานหน้าร้าน (ตัดสิทธิ์บัตรกำนัล)',
            self::CompanyPartner => 'บริษัทคู่ค้า (ผู้จัดหาสินค้า)',
        };
    }
}
