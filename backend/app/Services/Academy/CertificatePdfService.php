<?php

namespace App\Services\Academy;

use App\Models\UserCertification;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfInstance;

/**
 * Academy Sprint 6 — on-demand certificate PDF for a passed cert tier.
 *
 * BR-7: exact certificate branding/wording is not a finalized business
 * value (no logo field even exists on Company yet — see Company model),
 * so this deliberately renders only fields that already exist as real
 * data (company name, agent name, cert tier name, passed_at) with plain
 * neutral layout/copy — nothing invented on the human's behalf. If the
 * human wants a logo/signature/seal, that's a new decision + schema
 * change for a future round, not guessed here.
 *
 * Rendered on-demand (not persisted to disk): a UserCertification row
 * never changes after creation (see model docblock), so there is
 * nothing to invalidate/regenerate — always rendering fresh avoids a
 * new `certificate_path` column + storage lifecycle for a document
 * that's cheap to regenerate from already-tenant-scoped DB data.
 *
 * HTML is built here via plain string interpolation, not a .blade.php
 * view — CLAUDE.md Section 3 says the backend "Functions strictly as a
 * RESTful API returning JSON — Blade templating is strictly forbidden".
 * That rule targets serving server-rendered HTML pages to the SPA in
 * place of JSON; a Blade view is never routed to a browser here, only
 * used internally as dompdf's HTML input for a binary PDF response
 * (the same category as the video/image binaries already streamed by
 * ModuleController/ProductSalesMaterialController). Out of an abundance
 * of caution this still avoids Blade entirely, so there's no ambiguity
 * against the letter of the rule.
 */
class CertificatePdfService
{
    public function render(UserCertification $certification): PdfInstance
    {
        $certification->loadMissing(['user', 'certTier', 'company']);

        return Pdf::loadHTML($this->buildHtml($certification))->setPaper('a4', 'landscape');
    }

    private function buildHtml(UserCertification $certification): string
    {
        $companyName = e($certification->company?->name ?? 'Sync Vision Agent');
        $agentName = e($certification->user?->name ?? '-');
        $tierName = e($certification->certTier?->name ?? '-');
        $passedAt = $certification->passed_at;
        $thaiDate = $passedAt
            ? sprintf('%d %s %d', $passedAt->day, $this->thaiMonth($passedAt->month), $passedAt->year + 543)
            : '-';

        return <<<HTML
            <html>
            <head>
                <meta charset="utf-8">
                <style>
                    body { font-family: "DejaVu Sans", sans-serif; text-align: center; padding-top: 60px; color: #1e293b; }
                    .border-box { border: 3px solid #1e3a8a; margin: 20px; padding: 50px 40px; }
                    .company { font-size: 14px; color: #64748b; letter-spacing: 2px; text-transform: uppercase; }
                    .title { font-size: 32px; font-weight: bold; margin-top: 20px; color: #1e3a8a; }
                    .lead { font-size: 14px; color: #475569; margin-top: 30px; }
                    .name { font-size: 28px; font-weight: bold; margin-top: 12px; }
                    .tier { font-size: 16px; margin-top: 20px; color: #334155; }
                    .date { font-size: 12px; color: #94a3b8; margin-top: 30px; }
                    .footer { font-size: 10px; color: #cbd5e1; margin-top: 40px; }
                </style>
            </head>
            <body>
                <div class="border-box">
                    <div class="company">{$companyName}</div>
                    <div class="title">Certificate of Completion</div>
                    <div class="lead">This is to certify that</div>
                    <div class="name">{$agentName}</div>
                    <div class="tier">has successfully passed the certification: {$tierName}</div>
                    <div class="date">{$thaiDate}</div>
                    <div class="footer">Issued automatically by Sync Vision Agent</div>
                </div>
            </body>
            </html>
            HTML;
    }

    private function thaiMonth(int $month): string
    {
        return [
            1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
            5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
            9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม',
        ][$month] ?? '';
    }
}
