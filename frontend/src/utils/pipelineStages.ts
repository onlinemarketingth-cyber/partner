/**
 * ADR-026 / CLAUDE.md §4.3 — Thai display labels + journey helpers for
 * the closed `PipelineStage` vocabulary.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * The API sends each stage as `{ key, label }` with an ENGLISH label:
 * `PipelineStage::label()` is deliberately English (§7 keeps code and
 * identifiers English) and its own docblock states that Thai stage
 * labels "belong to the UI layer, not the enum". So the Thai wording
 * lives here, in exactly one place per app, instead of being retyped in
 * every board and drawer that renders a stage.
 *
 * The three post-sale labels (จัดส่ง / นัดใช้บริการ / ติดตามผล) are NOT
 * invented here — they are the human's own words, recorded in CLAUDE.md
 * §4.3 and ADR-026 §5 Q1. The other five are straight translations of
 * the original §4.3 sequence; they carry no business meaning of their
 * own.
 *
 * Unknown key → fall back to whatever the API sent. Adding a stage case
 * is a backend change plus an ADR (ADR-026 §3.2), and until this map
 * catches up the board must still render that stage rather than a blank
 * or a raw snake_case key.
 *
 * Presentation only. Nothing here decides which stage is legal, which
 * move is allowed, or what order a journey runs in — all of that is the
 * referral's own `pipeline` payload from the server (§7).
 */

/** One stage as ReferralResource / PipelineTemplateResource send it. */
export interface PipelineStageRef {
  key: string
  label: string
}

/**
 * The one stage key with a rule attached to it: BR-4 fires commission
 * at Complete Payment and nowhere else, on EVERY template (ADR-026 is
 * explicit that this is unchanged). Named here so the screens that
 * highlight it are not each carrying a bare string literal (§7).
 */
export const PAYMENT_STAGE_KEY = 'complete_payment'

export const PIPELINE_STAGE_LABELS_TH: Record<string, string> = {
  complete_registered: 'ลงทะเบียนสำเร็จ',
  waiting_appointment: 'รอนัดหมาย',
  finish_1st_doctor_meeting: 'พบแพทย์ครั้งแรกแล้ว',
  complete_payment: 'ชำระเงินสำเร็จ',
  ongoing_next_meeting: 'นัดหมายครั้งถัดไป',
  // ADR-026 §5 Q1 — human's wording, 2026-08-08.
  delivery: 'จัดส่ง',
  service_appointment: 'นัดใช้บริการ',
  follow_up: 'ติดตามผล',
}

export function stageLabelTh(stage: PipelineStageRef): string {
  return PIPELINE_STAGE_LABELS_TH[stage.key] ?? stage.label
}

/**
 * The identity of a journey, for grouping referrals that share one
 * column set.
 *
 * Keyed on the ORDERED stage keys rather than on
 * `referrals.pipeline_template_id`, for two reasons:
 *
 *  1. Legacy referrals (pre-ADR-026) have a NULL template id but a real
 *     journey — the server falls them back to
 *     `PipelineStage::defaultSequence()`. Keying on the id would scatter
 *     every one of them into its own "unknown" bucket.
 *  2. Two templates with an identical stage sequence genuinely ARE the
 *     same board. Splitting them would show the user two columns sets
 *     that look and behave the same, for a difference (the template
 *     row's id) they cannot see and do not care about.
 *
 * An empty sequence — the fail-closed case where the template could not
 * be read (ReferralResource: "both arrays are EMPTY when the referral's
 * journey cannot be read") — gets its own signature so those referrals
 * are grouped together and flagged, never merged into a real journey.
 */
export function journeySignature(stages: PipelineStageRef[]): string {
  return stages.length ? stages.map((s) => s.key).join('>') : '__unreadable__'
}

/**
 * A human name for a journey.
 *
 * ReferralResource deliberately does not send the template's NAME (only
 * its stages and, for reference, its id), so the sequence itself is the
 * name — which is also the only thing that actually distinguishes one
 * board from another. Long journeys collapse to first → … → last plus a
 * step count so the label stays readable at 375px.
 */
export function journeyLabel(stages: PipelineStageRef[]): string {
  if (stages.length === 0) return 'เส้นทางนี้อ่านไม่ได้ (ตั้งค่าไม่ถูกต้อง)'

  const first = stages[0]
  const last = stages[stages.length - 1]
  if (!first || !last) return 'เส้นทางนี้อ่านไม่ได้ (ตั้งค่าไม่ถูกต้อง)'

  if (stages.length <= 3) return stages.map(stageLabelTh).join(' → ')

  return `${stageLabelTh(first)} → … → ${stageLabelTh(last)} (${stages.length} ขั้น)`
}
