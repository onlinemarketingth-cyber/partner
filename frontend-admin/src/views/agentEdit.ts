/**
 * TASK-129 — shared types + helpers for the agent-editing family of views.
 *
 * Why this module exists at all: TASK-129 pulled the "แก้ไขข้อมูลตัวแทน" form
 * out of AgentManagementView into <AgentEditModal>, and three things are
 * genuinely needed by BOTH files afterwards:
 *
 *   • `AgentItem` — the UserResource row shape. AgentManagementView types its
 *     roster with it and hands that roster to the modal as a prop.
 *   • the identity-document helpers — the modal's edit form AND the page's
 *     "+ เพิ่มตัวแทน" create form both render the same two controls, and a
 *     second copy of "13 digits for a Thai ID, 12 for a passport, upper-case
 *     a passport before sending" is exactly the kind of duplicate that starts
 *     lying the day App\Rules\IdDocument changes.
 *   • `fetchAllPages` — every index() endpoint in this backend paginates at 15
 *     with no ?per_page support (see its own docblock), so both files need it.
 *
 * Kept as a plain TS module beside the views, mirroring salesTeam.ts, rather
 * than exported out of the SFC — same folder, same precedent, no importing of
 * named bindings from a .vue file.
 */

import { api } from '@/api/client'

/**
 * TASK-123 (backend TASK-122) — mirrors App\Enums\IdDocumentType exactly.
 * These two strings are the only values /users accepts for
 * `id_document_type`; anything else is a 422, so this stays a closed union
 * and a fixed two-item control, never free text.
 */
export type IdDocumentType = 'thai_national_id' | 'passport'

/** '' = the admin has not chosen a type yet (both forms start there). */
export type IdDocumentTypeChoice = IdDocumentType | ''

export interface AgentItem {
  id: number
  name: string
  // TASK-128 — the edit modal binds these two, not `name`: `name` is a
  // DERIVED value (User::booted()'s saving() hook recomputes it from the
  // pair) and UpdateUserRequest has no `name` rule at all, so writing back
  // a single combined string would be silently dropped. UserResource sends
  // both unconditionally.
  first_name?: string | null
  last_name?: string | null
  email: string
  // TASK-131 — editable. It used to be read-only because UpdateUserRequest
  // had no `phone` rule, so validated() dropped it and the column was
  // effectively write-once at registration; the rule was added in TASK-131
  // and the modal's field followed. Still optional/nullable: an agent an
  // admin created has never had one, and clearing the box sends null.
  phone?: string | null
  role: 'agent' | 'company_admin'
  company: { id: number; name: string } | null
  has_passed_basic_cert: boolean | null
  is_active: boolean
  created_at: string
  // ADR-005/TASK-017..020 — additive, only meaningful for self-registered
  // agents (Admin-created agents default to approved/'email', see
  // TASK-017's migration backfill, so these never show for them).
  agent_approval_status?: 'pending' | 'approved' | 'rejected' | null
  approval_rejection_reason?: string | null
  registered_via?: 'email' | 'facebook' | 'line' | 'google' | null
  // TASK-112 / ADR-025 §1 — a CAPABILITY flag, not a fourth role: `role`
  // above stays 'agent' for a team leader. It grants exactly two things
  // (minting recruit links, approving their own recruits) and changes
  // NOTHING about what the agent can see — see the panel copy in the modal.
  is_team_leader?: boolean
  // TASK-115 / ADR-025 §7 — which path admitted this agent, when, and by
  // whom. These are the mitigation ADR-025 §7 leans on in exchange for
  // accepting leader self-approval, so the approvals queue renders all three.
  approval_source?: 'admin' | 'team_leader' | null
  approved_at?: string | null
  // OPTIONAL AT TWO LEVELS, and the difference matters:
  //   * key ABSENT (undefined) — UserResource wraps this in whenLoaded(),
  //     so /users (which does not eager-load approvedBy) simply omits it;
  //   * key PRESENT but null — either nobody is recorded (rows approved
  //     before TASK-115, and Admin-created agents, which were never an
  //     "approval" event at all), or a SUPER ADMIN approved and TenantScope
  //     hides that user row from this Company Admin.
  // Neither case may ever render as "null" — approvalProvenance() in
  // AgentManagementView maps both to explicit Thai copy.
  approved_by?: { id: number; name: string; is_team_leader: boolean } | null
  // TASK-025 / ADR-006 — this agent's upline. Same-company + no-cycle
  // are enforced server-side (UserService::assertValidManager) — the
  // dropdown in the modal only excludes obviously-invalid picks (self) as a
  // convenience; the server is still the real guard (BR-6).
  manager_id?: number | null
  manager?: { id: number; name: string } | null
  // TASK-044 Phase A — bank payout details. As of TASK-047, UserResource
  // now reveals the REAL bank_account_number here (Company Admin/Super
  // Admin managing an agent within their own company — see that
  // Resource's own docblock for the human-confirmed reasoning); no
  // longer always masked.
  bank_name?: string | null
  bank_account_number?: string | null
  bank_account_holder_name?: string | null
  // TASK-059 — Thai national ID (PDPA §6). Same reveal pattern as
  // bank_account_number above: UserResource sends the REAL national_id
  // to a privileged viewer (Company Admin/Super Admin managing this
  // agent), always sends the masked form too.
  national_id?: string | null
  national_id_masked?: string | null
  // TASK-122/123 — WHICH document the two fields above describe. NULLABLE
  // for real: every agent row created before TASK-122 has no type recorded,
  // and so does every agent whose document was never captured. UserResource
  // sends it unconditionally (it leaks no digits), so an absent key here
  // means the same thing as null — see idDocumentTypeLabel().
  id_document_type?: IdDocumentType | null
}

/** Thai wording identical to IdDocumentType::label() on the backend. */
export const ID_DOCUMENT_TYPE_OPTIONS: Array<{ value: IdDocumentType; label: string }> = [
  { value: 'thai_national_id', label: 'บัตรประชาชน' },
  { value: 'passport', label: 'หนังสือเดินทาง' },
]

/**
 * The recorded type, for display next to national_id_masked.
 *
 * NULL IS A REAL ANSWER, not a missing value to paper over: every row
 * created before TASK-122 has no type recorded. Rendering it as
 * "ไม่ได้ระบุ" is the honest answer — defaulting to "บัตรประชาชน" because
 * most of them probably are one would put a claim about a person's identity
 * document on screen that nothing in the database supports.
 */
export function idDocumentTypeLabel(type?: IdDocumentType | null): string {
  return ID_DOCUMENT_TYPE_OPTIONS.find((o) => o.value === type)?.label ?? 'ไม่ได้ระบุ'
}

export function idNumberPlaceholder(type: IdDocumentTypeChoice): string {
  if (type === 'thai_national_id') return 'เลขบัตรประชาชน 13 หลัก'
  if (type === 'passport') return 'เลขที่หนังสือเดินทาง เช่น AA1234567'
  return 'เลือกประเภทเอกสารก่อน'
}
/**
 * 13 for a Thai ID, 12 for the longest passport App\Rules\IdDocument
 * accepts. Left uncapped while no type is chosen — capping to 13 up front
 * would silently swallow characters of a passport number typed before the
 * selector was touched.
 */
export function idNumberMaxLength(type: IdDocumentTypeChoice): number {
  if (type === 'thai_national_id') return 13
  if (type === 'passport') return 12
  return 255
}
export function idNumberInputMode(type: IdDocumentTypeChoice): 'numeric' | 'text' {
  return type === 'thai_national_id' ? 'numeric' : 'text'
}

/**
 * TASK-130 §3 — make a "numbers only" field ACTUALLY refuse letters.
 *
 * `type="number"` does not do this: every browser still accepts `e`, `E`,
 * `+`, `-` and (in Chrome/Safari) leaves `input.value` as the empty string
 * for anything it cannot parse — so the admin sees "5e3" on screen while the
 * bound model reads '' and the field silently saves nothing. `inputmode` only
 * chooses which soft keyboard a phone offers; it blocks nothing on desktop.
 *
 * So the value is sanitised on every input event instead, and the DOM node is
 * rewritten in the same tick when it differs. That second part matters: with
 * a plain `:value` binding, typing "5a" leaves the model at '5' — unchanged —
 * so Vue re-renders nothing and the stray "a" stays visible in the box.
 * Assignment happens ONLY when the strings differ, so a valid keystroke never
 * moves the caret to the end.
 *
 * Digits only, deliberately: no '.', no '-'. Every field this guards is a
 * whole number — a count, a bank account number, a 13-digit Thai ID, and the
 * baht target that is multiplied by 100 into integer satang (BR-3), where a
 * decimal is exactly what must not get in.
 */
export function sanitizeDigitsInput(event: Event): string {
  const el = event.target as HTMLInputElement
  const digits = el.value.replace(/\D/g, '')
  if (el.value !== digits) el.value = digits
  return digits
}

/**
 * The same guard for the identity-document number, which is only sometimes
 * numeric: a Thai national ID is 13 digits, but a passport number is
 * alphanumeric BY DESIGN (App\Rules\IdDocument accepts letters), so stripping
 * there would make a valid passport untypeable.
 */
export function sanitizeIdNumberInput(type: IdDocumentTypeChoice, event: Event): string {
  if (type !== 'thai_national_id') return (event.target as HTMLInputElement).value
  return sanitizeDigitsInput(event)
}

/** Digits already typed before the admin picked "บัตรประชาชน" (see the watcher that calls this). */
export function digitsOnly(value: string): string {
  return value.replace(/\D/g, '')
}
/**
 * Passport letters go up on the wire to match what the field visually shows
 * (the input is CSS-uppercased). Harmless either way — the backend
 * upper-cases before deriving the blind index — but storing what the admin
 * appeared to type avoids a value that renders one way and is stored
 * another. A Thai ID is digits, so this is a no-op there.
 */
export function normalizeIdNumber(type: IdDocumentTypeChoice, raw: string): string {
  const trimmed = raw.trim()
  return type === 'passport' ? trimmed.toUpperCase() : trimmed
}

// Bug found 2026-07-22 while building the Agent Overview dashboard (Task-038):
// every index() endpoint in this backend calls Eloquent's paginate() with NO
// argument, which defaults to 15/page — and does NOT read a `?per_page=`
// query param (Laravel doesn't wire that up automatically; would need the
// controller to explicitly pass $request->input('per_page')). AgentManagementView's
// own loadAgents() was silently dropping every agent past page 1 — confirmed
// live: Thai Life has 27 users, only 15 were ever shown there. Same
// paginate()-with-no-args pattern exists on /referrals and /commission-ledger,
// so this loop is written once and reused rather than fixed in one place only.
export async function fetchAllPages<T>(path: string): Promise<T[]> {
  const sep = path.includes('?') ? '&' : '?'
  const first = await api.get<{ data: T[]; meta?: { last_page: number } }>(`${path}${sep}page=1`)
  const items = [...first.data]
  const lastPage = first.meta?.last_page ?? 1
  for (let page = 2; page <= lastPage; page++) {
    const next = await api.get<{ data: T[] }>(`${path}${sep}page=${page}`)
    items.push(...next.data)
  }
  return items
}
