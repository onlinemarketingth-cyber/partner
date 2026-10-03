/**
 * commission_ledger.payment_status → what the agent reads (MOB-12 follow-up,
 * owner decision 2026-10-03).
 *
 * Mirrors App\Enums\PaymentStatus::label() and frontend-admin's copy of this
 * file. The third value — `forfeited`, commission given up when an agent
 * deleted their own account — would otherwise have rendered as "รอจ่าย"
 * (money still owed) through the old inline `=== 'paid'` ternary. Nobody
 * signed in can normally see a forfeited row (the account it belongs to no
 * longer exists), but the label must still never claim money is owed.
 *
 * Classes are the portal's semantic surface/ink tokens (ADR-023).
 */
export type CommissionPaymentStatus = 'pending' | 'paid' | 'forfeited'

const LABELS: Record<CommissionPaymentStatus, string> = {
  pending: 'รอจ่าย',
  paid: 'จ่ายแล้ว',
  forfeited: 'สละสิทธิ์',
}

const CLASSES: Record<CommissionPaymentStatus, string> = {
  pending: 'text-ink-warning bg-surface-warning',
  paid: 'text-ink-success bg-surface-success',
  forfeited: 'text-ink-card-muted bg-surface-chip',
}

export function commissionPaymentStatusLabel(status: string | null | undefined): string {
  return LABELS[status as CommissionPaymentStatus] ?? status ?? '-'
}

export function commissionPaymentStatusClass(status: string | null | undefined): string {
  return CLASSES[status as CommissionPaymentStatus] ?? 'text-ink-card-muted bg-surface-chip'
}
