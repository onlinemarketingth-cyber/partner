/**
 * commission_ledger.payment_status → what an admin reads (MOB-12 follow-up,
 * owner decision 2026-10-03).
 *
 * Mirrors App\Enums\PaymentStatus::label(). There used to be two values and
 * every screen wrote `status === 'paid' ? 'จ่ายแล้ว' : 'รอจ่าย'` inline; the
 * third value — `forfeited`, commission an agent gave up when deleting their
 * own account — would have rendered as "รอจ่าย" through every one of those
 * ternaries, i.e. as money still owed. One map, so a fourth value can only be
 * missed in one place.
 *
 * Unknown values fall back to the raw string rather than to "รอจ่าย": a label
 * that claims money is owed must only ever appear when it is.
 */
export type CommissionPaymentStatus = "pending" | "paid" | "forfeited";

const LABELS: Record<CommissionPaymentStatus, string> = {
  pending: "รอจ่าย",
  paid: "จ่ายแล้ว",
  forfeited: "สละสิทธิ์",
};

const CLASSES: Record<CommissionPaymentStatus, string> = {
  pending: "text-amber-600 bg-amber-50",
  paid: "text-emerald-600 bg-emerald-50",
  forfeited: "text-slate-500 bg-slate-100",
};

export function commissionPaymentStatusLabel(
  status: string | null | undefined,
): string {
  return LABELS[status as CommissionPaymentStatus] ?? status ?? "-";
}

export function commissionPaymentStatusClass(
  status: string | null | undefined,
): string {
  return (
    CLASSES[status as CommissionPaymentStatus] ?? "text-slate-500 bg-slate-100"
  );
}
