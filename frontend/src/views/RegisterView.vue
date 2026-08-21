<script setup lang="ts">
/**
 * RegisterView — Agent self-registration (ADR-005 / TASK-018).
 *
 * Public, unauthenticated route (meta.public — see router/index.ts).
 * Visual treatment intentionally mirrors LoginView.vue (same gradient
 * canvas, white rounded-[28px] card, pill lang toggle, AppLogo) so the
 * two public entry points feel like one family rather than two designs
 * — see LoginView.vue's own CI-001/CI-002 comments for the full history
 * of that treatment; nothing new is introduced here.
 *
 * Flow (ADR-005 §"Registration Flow"):
 *   1) Invite code → POST /register/resolve-invite-code → show the
 *      resolved company name for confirmation before continuing.
 *   2) Full registration form → POST /register.
 *   3) Success screen — "confirm your email" (BR/ADR-005 decision 4:
 *      mandatory email verification for the email/password channel).
 *
 * ─────────────────────────────────────────────────────────────────────────
 * TASK-116 / ADR-025 §5 — THE SECOND WAY IN: `?ref=<token>`.
 *
 * A team leader's recruit link lands here. The token already carries the
 * company (from its inviter), so step 1 is SKIPPED entirely — asking for an
 * invite code on top would create a second, silent source of truth for which
 * tenant the recruit joins, which is exactly what §5 forbids. The two
 * credentials are mutually exclusive on the wire too: POST /register takes
 * `invite_code` OR `ref_token`, never both (RegisterRequest 422s otherwise),
 * so this view sends exactly one and never carries the other alongside.
 *
 * FAILURE PATH, deliberately not a dead end. `resolve-ref-token` answers 404
 * for every unusable reason at once — unknown, expired, revoked, quota
 * exhausted, or an inviter who was deactivated / de-flagged / moved company
 * — and it will not say which (an anonymous caller must not be able to probe
 * a leader's state). So the view cannot diagnose it either. What it CAN do is
 * refuse to strand the person: the token is dropped, the normal invite-code
 * step is restored, and one line explains that the link no longer works and
 * that an invite code will do instead. A recruit who was sent a stale link
 * still has a way to finish signing up.
 *
 * The same fallback runs if the token dies BETWEEN the resolve and the
 * submit (see applyServerFieldErrors) — the resolve is only a courtesy
 * check; the authoritative one happens inside the backend's transaction.
 *
 * Required-field validation reuses the exact asterisk + inline-error +
 * focus-on-invalid pattern built for ClientsView.vue/ReferralsView.vue
 * this same sprint (human request, 2026-07-13) — nothing new invented.
 *
 * Social login (Facebook/LINE/Google) is TASK-019, not built yet — the
 * "หรือสมัครด้วย" section is deliberately left out of this view rather
 * than stubbed with dead buttons, to avoid promising a channel that
 * doesn't work yet.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { api, ApiError, ensureCsrfCookie } from '@/api/client'
// Only used for the one-off "you are previewing your own link" toast — this
// view never gates on the session, and an anonymous recruit never triggers it.
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import { useI18n } from '@/composables/useI18n'
import Icon from '@/design-system/components/Icon.vue'
import AppLogo from '@/design-system/components/AppLogo.vue'
import NationalIdSegments from '@/design-system/components/NationalIdSegments.vue'

const { lang, t, setLang } = useI18n()
const route = useRoute()
const auth = useAuthStore()
const toast = useToastStore()

function toggleLang() {
  setLang(lang.value === 'TH' ? 'EN' : 'TH')
}

type Step = 'invite' | 'form' | 'done'
const step = ref<Step>('invite')

// --- TASK-116: arriving through a team leader's recruit link (?ref=) ---

/**
 * The token from the URL, kept only while it is believed good. Cleared the
 * moment the server rejects it, because it is what submitRegister() keys on
 * to decide WHICH credential to send — an empty string here means "fall back
 * to the invite-code path", and the two must never both be populated (the
 * backend 422s on both keys, ADR-025 §5).
 */
const refToken = ref('')
const refCompanyName = ref('')
const refInviterName = ref('')
/**
 * True while the token is being checked — the invite step must not flash.
 * Seeded from the query at SETUP time, not defaulted to true: a visitor with
 * no ?ref= must never see a "checking your link" line for a link they do not
 * have, not even for one frame.
 */
// TASK-232 — /j/<code> puts the token in the PATH, so seeding this from
// the query alone would skip the "checking your link" line for exactly the
// links this feature added. Still seeded at setup rather than defaulted to
// true: a visitor with no link must never see that line for one frame.
const hasTeamLink =
  (typeof route.query.ref === 'string' && route.query.ref.trim().length > 0) ||
  (route.name === 'team-signup-link' && typeof route.params.code === 'string')
const resolvingRef = ref(hasTeamLink)
/** Explains a dropped token on the invite step. Never a dead end. */
const refFallbackMessage = ref('')

const viaRecruitLink = computed(() => Boolean(refToken.value))

/**
 * TASK-233 — arrived on /c/<code>, the company's own signup link.
 *
 * FOUND IN UAT, 2026-08-20. The two computeds below already suppressed the
 * step counter and the "enter your invite code" subtitle for a recruit-link
 * arrival, with a comment explaining exactly why: the link already did what
 * step 1 exists to do, so advertising a step this person never saw and
 * cannot go back to is a lie. A company-link arrival is the SAME situation
 * and was not covered — the page told a recruit to "กรอกรหัสเชิญของบริษัท"
 * on a screen with no such field, directly under a heading claiming they
 * were on step 2 of 2.
 *
 * Kept as its own flag rather than folded into viaRecruitLink: they are
 * different arrivals with different copy (one names a team leader, one does
 * not), and the two places that need "either kind of link" say so.
 */
const viaCompanyLink = computed(() => Boolean(companyLinkCode.value))
const companyLinkCode = ref('')

/** Either kind of link resolved the company for them, so step 1 never happened. */
const arrivedViaLink = computed(() => viaRecruitLink.value || viaCompanyLink.value)

function fallBackToInviteStep(message: string) {
  refToken.value = ''
  refCompanyName.value = ''
  refInviterName.value = ''
  refFallbackMessage.value = message
  step.value = 'invite'
}

onMounted(async () => {
  /*
   * TASK-233 — /c/<code>: THE COMPANY'S OWN SIGNUP LINK.
   *
   * This is the case that did not exist at all before. A recruit reaching
   * /register with no link had to be handed a code out of band and type it
   * in; the branded /login?company=<slug> link people were already sharing
   * only themed the login page and never carried the company into
   * registration at all.
   *
   * Handled here rather than in a view of its own because the ONLY
   * difference from typing the code by hand is that the code arrives in
   * the URL. Everything after the resolve is identical, and a second copy
   * of this form is a second copy to keep true.
   */
  const companyCode = typeof route.params.code === 'string' && route.name === 'company-signup-link'
    ? route.params.code.trim()
    : ''

  if (companyCode) {
    resolvingRef.value = false
    inviteCode.value = companyCode
    await submitInviteCode()

    // Only once it RESOLVED. A dead link falls back to the ordinary code
    // form, where the step counter and the "enter your code" line are both
    // true again and must come back.
    if (step.value === 'form') companyLinkCode.value = companyCode

    // submitInviteCode() sets step to 'form' on success and leaves an
    // explanatory error on the invite step otherwise. A dead /c/ link
    // therefore lands on the ordinary code form with the reason shown,
    // rather than on a dead end — the same "recover, never strand" rule
    // the ?ref= path below follows.
    return
  }

  // TASK-232 — /j/<code> carries the team invite in the path; ?ref=<token>
  // is the original 64-character form, which keeps working forever because
  // leaders have already sent it to people.
  const raw = route.name === 'team-signup-link' ? route.params.code : route.query.ref
  const token = typeof raw === 'string' ? raw.trim() : ''
  if (!token) {
    // Neither form of link — ordinary invite-code registration.
    resolvingRef.value = false
    return
  }

  // A logged-in visitor on a `?ref=` link is the leader checking their own
  // link (an anonymous recruit never hits this branch). Human instruction
  // 2026-08-05: say it once as a toast and let it go — the earlier version
  // was a permanent banner, which competed with the form for attention and
  // read as an error on a page where nothing is wrong.
  if (auth.isAuthenticated) {
    toast.info('นี่คือหน้าที่ลูกทีมจะเห็นเมื่อกดลิงก์ของคุณ')
  }

  try {
    // Same reason submitInviteCode() below needs it: Sanctum applies CSRF
    // verification to every request from the SPA's stateful domain, so the
    // first POST a visitor makes has to obtain the cookie first.
    await ensureCsrfCookie()
    const res = await api.post<{ company_name: string; inviter_name: string }>(
      '/register/resolve-ref-token',
      { ref_token: token },
    )
    refToken.value = token
    refCompanyName.value = res.company_name
    refInviterName.value = res.inviter_name
    // The link IS the invitation — skip step 1 (ADR-025 §5).
    step.value = 'form'
  } catch (e) {
    // 404 for every unusable reason, and the endpoint will not say which.
    // See the file docblock: recover, never strand.
    fallBackToInviteStep(
      e instanceof ApiError
        ? t(
            'reg_ref_invalid',
            'ลิงก์ชวนเข้าทีมนี้ใช้ไม่ได้แล้ว (หมดอายุ ถูกยกเลิก หรือมีผู้สมัครครบจำนวนแล้ว) — หากมีรหัสเชิญของบริษัท กรอกด้านล่างเพื่อสมัครต่อได้เลย หรือขอลิงก์ใหม่จากหัวหน้าทีมของคุณ',
            'This team invite link is no longer usable (expired, revoked, or fully used). Enter your company invite code below to continue, or ask your team leader for a new link.',
          )
        : t(
            'reg_ref_network',
            'ตรวจสอบลิงก์ชวนเข้าทีมไม่สำเร็จ กรุณาลองใหม่ หรือกรอกรหัสเชิญของบริษัทเพื่อสมัครต่อ',
            'Could not check the team invite link. Please try again, or enter your company invite code to continue.',
          ),
    )
  } finally {
    resolvingRef.value = false
  }
})

// --- Step 1: invite code ---
const inviteCode = ref('')
const inviteCodeInputEl = ref<HTMLInputElement | null>(null)
const inviteCodeError = ref('')
const resolvedCompanyName = ref('')
const resolvingCode = ref(false)

async function submitInviteCode() {
  inviteCodeError.value = ''
  if (!inviteCode.value.trim()) {
    inviteCodeError.value = t('reg_invite_required', 'กรุณากรอกรหัสเชิญ', 'Invite code is required')
    inviteCodeInputEl.value?.focus()
    return
  }
  resolvingCode.value = true
  try {
    // Bug fix (2026-07-14): this call was missing ensureCsrfCookie(),
    // unlike login()/submitRegister() below — Sanctum's statefulApi()
    // applies CSRF verification to every request from the SPA's
    // stateful domain, not just authenticated ones, so the very first
    // POST a visitor makes (before any cookie exists) always 419'd.
    await ensureCsrfCookie()
    const res = await api.post<{ company_name: string }>('/register/resolve-invite-code', {
      invite_code: inviteCode.value.trim(),
    })
    resolvedCompanyName.value = res.company_name
    step.value = 'form'
  } catch (e) {
    // 404 either way (unknown/expired/revoked) — see ResolveInviteCodeRequest's
    // own comment on why the backend never distinguishes the reason.
    inviteCodeError.value =
      e instanceof ApiError
        ? t('reg_invite_invalid', 'ไม่พบรหัสเชิญนี้ หรือรหัสหมดอายุ/ถูกยกเลิกแล้ว', 'Invite code not found, or it has expired/been revoked')
        : t('reg_network_error', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาลองใหม่อีกครั้ง', 'Could not reach the server. Please try again.')
    inviteCodeInputEl.value?.focus()
  } finally {
    resolvingCode.value = false
  }
}

function changeInviteCode() {
  step.value = 'invite'
  resolvedCompanyName.value = ''
}

// --- Step 2: registration form ---

/**
 * TASK-123 (backend TASK-122) — the identity document is now REQUIRED on
 * both self-registration paths, so this union mirrors App\Enums\IdDocumentType
 * exactly. Anything else is a 422 on `id_document_type`, which means these
 * two strings are the only values this view may ever put on the wire — hence
 * a closed union and a two-item control, never a free-text field.
 */
type IdDocumentType = 'thai_national_id' | 'passport'

const form = ref({
  first_name: '',
  last_name: '',
  email: '',
  phone: '',
  // Defaulted, not blank: the overwhelming majority of registrants hold a
  // Thai national ID, and a pre-selected sensible default is one less
  // decision to make on a phone. RegisterRequest still requires the field,
  // so the default is a convenience, never an assumption the server trusts.
  id_document_type: 'thai_national_id' as IdDocumentType,
  national_id: '',
  password: '',
  password_confirmation: '',
})

const firstNameInputEl = ref<HTMLInputElement | null>(null)
const lastNameInputEl = ref<HTMLInputElement | null>(null)
const emailInputEl = ref<HTMLInputElement | null>(null)
const nationalIdInputEl = ref<HTMLInputElement | null>(null)
/** Only mounted on the Thai path — see focusNationalId(). */
const nationalIdSegmentsEl = ref<{ focus: () => void } | null>(null)
const passwordInputEl = ref<HTMLInputElement | null>(null)
const passwordConfirmInputEl = ref<HTMLInputElement | null>(null)

const firstNameError = ref('')
const lastNameError = ref('')
const emailError = ref('')
const idDocumentTypeError = ref('')
const nationalIdError = ref('')
const passwordError = ref('')
const passwordConfirmError = ref('')

/**
 * "This address already has an account" — told while the form is being
 * filled in, not after it is submitted.
 *
 * ── WHY IT IS WORTH A ROUND TRIP ──
 *
 * The email IS the login identity here, so a recruit whose address is
 * already registered cannot finish this form no matter what else they type.
 * Before this they learned that at the very end, from a red banner under a
 * submit button, having already produced a national ID and chosen a
 * password. And the usual cause is the least dramatic one: they signed up
 * already and what they actually want is the login page — which is why the
 * message below is a route, not just a complaint.
 *
 * ── THE ENDPOINT IS AN ACCOUNT-EXISTENCE ORACLE ──
 *
 * It is gated server-side on the same live invite code or recruit token
 * that the submit demands, which is the whole reason it can exist at all —
 * see CheckEmailRequest's docblock. This view must therefore always send
 * the credential it is holding; a call without one is refused, by design.
 *
 * ── AND IT IS NEVER AUTHORITATIVE ──
 *
 * `unique:users,email` and the unique index are the real gate. The address
 * can be taken in the seconds between this answer and the submit, and the
 * submit still handles that. What this removes is the wasted five minutes,
 * not the check.
 */
const emailTaken = ref(false)
const checkingEmail = ref(false)

let emailCheckTimer: ReturnType<typeof setTimeout> | undefined
/**
 * Guards against a slow answer for an OLD address landing after a fast one
 * for the address now on screen. Without it, correcting a typo can leave
 * the previous address's verdict showing under the new one — and the person
 * has no way to tell it is stale.
 */
let emailCheckSeq = 0

/**
 * Deliberately loose: enough to know the person has stopped mid-typing, not
 * a second opinion on what a valid address is. RFC-shaped email regexes are
 * famously wrong at the edges, and being wrong here would mean refusing to
 * CHECK a perfectly good address — the server's `email` rule stays the
 * authority on validity.
 */
function looksLikeACompleteAddress(value: string): boolean {
  return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value.trim())
}

/**
 * The credential this signup is running on. Null before step 1 resolved,
 * which is also when there is nothing to check against.
 */
function signupCredential(): Record<string, string> | null {
  if (refToken.value) return { ref_token: refToken.value }
  if (inviteCode.value.trim()) return { invite_code: inviteCode.value.trim() }

  return null
}

async function checkEmailAvailability(): Promise<void> {
  const email = form.value.email.trim()
  const credential = signupCredential()

  if (!looksLikeACompleteAddress(email) || !credential) return

  const seq = ++emailCheckSeq
  checkingEmail.value = true

  try {
    const res = await api.post<{ available: boolean }>('/register/check-email', { email, ...credential })

    // A newer keystroke already started a newer check — that one owns the
    // answer now.
    if (seq !== emailCheckSeq) return

    emailTaken.value = res.available === false
  } catch {
    // FAILS SILENT, ON PURPOSE. This is a convenience layered on top of a
    // server-side rule that still runs at submit. A rate limit (20/min), a
    // dropped connection or a flaky network must never turn into a red
    // message on a form the person can still legitimately send — and must
    // never claim an address is free either. Saying nothing leaves them
    // exactly where they were before this feature existed.
  } finally {
    if (seq === emailCheckSeq) checkingEmail.value = false
  }
}

/** Debounced so it fires when typing stops, not once per character. */
function scheduleEmailCheck(): void {
  clearTimeout(emailCheckTimer)
  emailCheckTimer = setTimeout(() => void checkEmailAvailability(), 450)
}

function onEmailInput(): void {
  emailError.value = ''
  // Cleared on every keystroke: a verdict about a DIFFERENT address is
  // worse than no verdict, and this is the only moment we can be certain
  // the one on screen has changed.
  emailTaken.value = false
  scheduleEmailCheck()
}

function onEmailBlur(): void {
  // Leaving the field is a stronger "I am done with this" than any pause,
  // so do not sit out the rest of the debounce.
  clearTimeout(emailCheckTimer)
  void checkEmailAvailability()
}

// A pending timer on a torn-down component would fire into a dead view.
// Harmless today (the handler only touches refs) and exactly the kind of
// thing that stops being harmless the moment someone adds a toast to it.
onUnmounted(() => clearTimeout(emailCheckTimer))

const idDocumentTypeOptions = computed<Array<{ value: IdDocumentType; label: string }>>(() => [
  // Thai wording deliberately identical to IdDocumentType::label() on the
  // backend, so the label an admin later reads on the agent's record is the
  // same words the agent chose here.
  { value: 'thai_national_id', label: t('reg_id_type_thai', 'บัตรประชาชน', 'Thai national ID') },
  { value: 'passport', label: t('reg_id_type_passport', 'หนังสือเดินทาง', 'Passport') },
])

const isThaiIdDocument = computed(() => form.value.id_document_type === 'thai_national_id')

/**
 * Label / placeholder / hint / keyboard all follow the SELECTED type. The
 * two documents have nothing in common as far as the person typing is
 * concerned — one is 13 digits off a card, the other is letters and digits
 * off a passport — so a single neutral label would be wrong for both, and a
 * numeric keypad in front of someone holding a passport is simply broken.
 */
const documentNumberLabel = computed(() =>
  isThaiIdDocument.value
    ? t('reg_id_number_thai', 'เลขบัตรประชาชน 13 หลัก', 'Thai national ID (13 digits)')
    : t('reg_id_number_passport', 'เลขที่หนังสือเดินทาง', 'Passport number'),
)
const documentNumberPlaceholder = computed(() =>
  isThaiIdDocument.value
    ? t('reg_id_number_thai_ph', 'กรอกตัวเลข 13 หลัก', 'Enter 13 digits')
    : t('reg_id_number_passport_ph', 'เช่น AA1234567', 'e.g. AA1234567'),
)
const documentNumberHint = computed(() =>
  isThaiIdDocument.value
    ? t(
        'reg_id_number_thai_hint',
        'กรอกตามกลุ่มตัวเลขที่พิมพ์บนหน้าบัตร ครบแล้วระบบจะเลื่อนช่องถัดไปให้เอง',
        'Type each group as printed on your card — it moves to the next box for you.',
      )
    : t(
        'reg_id_number_passport_hint',
        'ตัวอักษรภาษาอังกฤษและตัวเลข 6-12 ตัว ตามที่ปรากฏบนหนังสือเดินทาง',
        '6-12 letters and digits, exactly as printed in your passport.',
      ),
)

function selectIdDocumentType(next: IdDocumentType) {
  if (form.value.id_document_type === next) return
  form.value.id_document_type = next
  // CLEAR THE NUMBER WHENEVER THE TYPE CHANGES. Leaving a 13-digit Thai ID
  // sitting in a field now labelled "passport" is a guaranteed 422, and a
  // confusing one: the server would answer "6-12 letters or digits" about
  // digits the person typed for a different document entirely. The reverse
  // is worse — a passport number under a Thai-ID label fails a checksum the
  // person has no way to reason about. The two documents share one column on
  // the server; they must not share a value in this form.
  form.value.national_id = ''
  nationalIdError.value = ''
  idDocumentTypeError.value = ''
}

/**
 * Focus whichever control is currently showing the document number.
 *
 * The two document types are rendered by two different things — a Thai ID
 * by NationalIdSegments (five boxes), a passport by a plain input — so a
 * single template ref cannot reach both. Every "put the cursor back on the
 * bad field" path goes through here, because a validation message on a
 * field the person then has to hunt for is barely better than no message.
 *
 * ── THE HISTORY THIS REPLACES ──
 *
 * The first fix for the 2026-08-21 report ("validate บัตรประชาชนไม่ผ่าน")
 * was a single field that stripped separators as they were typed. That
 * removed the truncation bug — a card typed as "1-1017-00230-70-8" used to
 * hit maxlength="13" part-way through, and the server then said "must be 13
 * digits" to somebody who had typed thirteen. The boxes remove the same bug
 * a better way: the person can now see their number in the same groups the
 * card prints, so a mistyped digit is visible BEFORE a mod-11 checksum
 * rejects the whole number without saying which digit was wrong.
 *
 * The checksum itself is still not duplicated in the browser — see
 * NationalIdSegments.vue and validateForm() below.
 */
function focusNationalId(): void {
  if (isThaiIdDocument.value) {
    nationalIdSegmentsEl.value?.focus()

    return
  }

  nationalIdInputEl.value?.focus()
}

const showPassword = ref(false)
const showPasswordConfirm = ref(false)
const submitting = ref(false)
const errorMessage = ref('')

function clearFieldErrors() {
  firstNameError.value = ''
  lastNameError.value = ''
  emailError.value = ''
  idDocumentTypeError.value = ''
  nationalIdError.value = ''
  passwordError.value = ''
  passwordConfirmError.value = ''
}

function validateForm(): boolean {
  clearFieldErrors()
  if (!form.value.first_name.trim()) {
    firstNameError.value = t('reg_first_name_required', 'กรุณากรอกชื่อ', 'First name is required')
    firstNameInputEl.value?.focus()
    return false
  }
  if (!form.value.last_name.trim()) {
    lastNameError.value = t('reg_last_name_required', 'กรุณากรอกนามสกุล', 'Last name is required')
    lastNameInputEl.value?.focus()
    return false
  }
  if (!form.value.email.trim()) {
    emailError.value = t('reg_email_required', 'กรุณากรอกอีเมล', 'Email is required')
    emailInputEl.value?.focus()
    return false
  }
  // Saves a round trip whose only possible outcome is the 422 that
  // applyServerFieldErrors() would then show in this same spot. NOT a
  // replacement for that path: emailTaken is only ever set by an answer we
  // received, so a check that never ran, was rate-limited or failed leaves
  // this false and the submit goes through to the real gate.
  if (emailTaken.value) {
    emailInputEl.value?.focus()
    return false
  }
  // TASK-123 — required on this path (RegisterRequest), unlike the Admin
  // create form where the same two fields stay optional. Only PRESENCE is
  // checked here: the 13-digit mod-11 checksum and the passport shape have
  // exactly one implementation, App\Rules\IdDocument, and a second copy in
  // Vue is how the two drift apart.
  if (!form.value.id_document_type) {
    idDocumentTypeError.value = t(
      'reg_id_type_required',
      'กรุณาเลือกประเภทเอกสารยืนยันตัวตน',
      'Please choose your ID document type',
    )
    return false
  }
  if (!form.value.national_id.trim()) {
    nationalIdError.value = isThaiIdDocument.value
      ? t('reg_id_number_thai_required', 'กรุณากรอกเลขบัตรประชาชน', 'Please enter your Thai national ID')
      : t('reg_id_number_passport_required', 'กรุณากรอกเลขที่หนังสือเดินทาง', 'Please enter your passport number')
    focusNationalId()
    return false
  }
  if (!form.value.password) {
    passwordError.value = t('reg_password_required', 'กรุณากรอกรหัสผ่าน', 'Password is required')
    passwordInputEl.value?.focus()
    return false
  }
  if (form.value.password.length < 8) {
    passwordError.value = t('reg_password_too_short', 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร', 'Password must be at least 8 characters')
    passwordInputEl.value?.focus()
    return false
  }
  if (form.value.password_confirmation !== form.value.password) {
    passwordConfirmError.value = t('reg_password_mismatch', 'รหัสผ่านไม่ตรงกัน', 'Passwords do not match')
    passwordConfirmInputEl.value?.focus()
    return false
  }
  return true
}

// Backend Form Request errors come back keyed by field name — map them
// onto the same inline-error refs so a 422 caught late (e.g. duplicate
// email, invite code expired between step 1 and step 2) still lands
// exactly where the human is looking, not just a generic banner.
function applyServerFieldErrors(errors: Record<string, string[]>) {
  if (errors.first_name?.[0]) { firstNameError.value = errors.first_name[0]; firstNameInputEl.value?.focus() }
  else if (errors.last_name?.[0]) { lastNameError.value = errors.last_name[0]; lastNameInputEl.value?.focus() }
  else if (errors.email?.[0]) { emailError.value = errors.email[0]; emailInputEl.value?.focus() }
  // TASK-123 — no focus() for the type: the control is a two-item selector
  // that always holds a valid value, so this branch only fires if the server
  // and this view ever disagree about the allowed values. There is nothing
  // for the person to type, so stealing focus would only move the page away
  // from the field they can actually fix.
  else if (errors.id_document_type?.[0]) { idDocumentTypeError.value = errors.id_document_type[0] }
  // Everything about the document NUMBER lands here, including the
  // per-company duplicate ("เลขที่เอกสารนี้ถูกใช้สมัครสมาชิกในบริษัทนี้แล้ว",
  // raised by RegistrationService, not by the Form Request). It is rendered
  // inline on the number field rather than as a banner because that is the
  // only field the person can change to get past it — and the backend
  // deliberately names nobody, so there is nothing else to show.
  else if (errors.national_id?.[0]) { nationalIdError.value = errors.national_id[0]; focusNationalId() }
  else if (errors.password?.[0]) { passwordError.value = errors.password[0]; passwordInputEl.value?.focus() }
  else if (errors.invite_code?.[0]) {
    // The code that worked at step 1 stopped being valid by the time
    // step 2 was submitted (expired/revoked in the meantime) — send the
    // person back to re-enter it rather than showing a dead-end error.
    errorMessage.value = errors.invite_code[0]
    step.value = 'invite'
  } else if (errors.ref_token?.[0]) {
    // TASK-116 — the recruit link died between the resolve on mount and
    // this submit: revoked, expired, or (the interesting one) the last
    // seat of a `max_uses` quota taken by someone else in the meantime.
    // The backend decides that atomically under a row lock, so this is a
    // real and expected race, not a defect. Same recovery as a bad token
    // on arrival: drop it, restore the invite-code step, explain.
    // The message is the backend's own plain-language wording.
    fallBackToInviteStep(errors.ref_token[0])
  }
}

async function submitRegister() {
  if (submitting.value) return
  if (!validateForm()) return
  errorMessage.value = ''
  submitting.value = true
  try {
    await ensureCsrfCookie()
    await api.post('/register', {
      // EXACTLY ONE of these two ever goes on the wire (ADR-025 §5) — the
      // other key is omitted entirely rather than sent as null/'', because
      // RegisterRequest's Rule::prohibitedIf fires on `filled()` and a
      // request carrying both is a 422 by design.
      ...(viaRecruitLink.value
        ? { ref_token: refToken.value }
        : { invite_code: inviteCode.value.trim() }),
      first_name: form.value.first_name,
      last_name: form.value.last_name,
      email: form.value.email,
      phone: form.value.phone || undefined,
      // TASK-123 — both required by RegisterRequest; the type is sent FIRST
      // in the payload for readability only (order is irrelevant on the
      // wire, but App\Rules\IdDocument reads the type to decide which check
      // to run, so the two always travel together).
      id_document_type: form.value.id_document_type,
      // Passport letters go up on the wire to match what the field visually
      // shows (the input is CSS-uppercased). Harmless either way — the
      // backend upper-cases before deriving the blind index — but storing
      // what the person appeared to type avoids a value that renders one way
      // and is stored another. Thai IDs are digits, so this is a no-op there.
      national_id: isThaiIdDocument.value
        ? form.value.national_id.trim()
        : form.value.national_id.trim().toUpperCase(),
      password: form.value.password,
      password_confirmation: form.value.password_confirmation,
    })
    step.value = 'done'
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      const body = e.body as { errors?: Record<string, string[]> }
      applyServerFieldErrors(body.errors ?? {})
      if (!errorMessage.value) {
        errorMessage.value = t('reg_error_generic', 'สมัครไม่สำเร็จ กรุณาตรวจสอบข้อมูลและลองใหม่', 'Registration failed. Please check your details and try again.')
      }
    } else {
      errorMessage.value = t('reg_network_error', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาลองใหม่อีกครั้ง', 'Could not reach the server. Please try again.')
    }
  } finally {
    submitting.value = false
  }
}

const stepLabel = computed(() => {
  // A recruit-link arrival has ONE step, not two — the link already did what
  // step 1 exists to do. Showing "ขั้นตอน 2 จาก 2" would advertise a step
  // this person never saw and cannot go back to.
  if (arrivedViaLink.value) return ''
  if (step.value === 'invite') return t('reg_step_1', 'ขั้นตอน 1 จาก 2', 'Step 1 of 2')
  if (step.value === 'form') return t('reg_step_2', 'ขั้นตอน 2 จาก 2', 'Step 2 of 2')
  return ''
})

const introLine = computed(() => {
  if (viaRecruitLink.value) {
    return t(
      'reg_sub_ref',
      'กรอกข้อมูลเพื่อสมัครเข้าทีม',
      'Fill in your details to join the team',
    )
  }
  if (viaCompanyLink.value) {
    return t(
      'reg_sub_company_link',
      'กรอกข้อมูลเพื่อสมัครเป็นตัวแทน',
      'Fill in your details to apply as an agent',
    )
  }

  return t('reg_sub', 'กรอกรหัสเชิญของบริษัทเพื่อเริ่มสมัคร', 'Enter your company invite code to get started')
})
</script>

<template>
  <div
    class="min-h-screen w-full flex items-center justify-center p-4 sm:p-8 font-sans"
    style="background: linear-gradient(160deg, #eef0f2 0%, #dde1e6 45%, #cfd4da 100%);"
  >
    <div class="w-full max-w-xl rounded-[28px] bg-surface-card shadow-xl border border-line-card/80 overflow-hidden p-8 sm:p-12">
      <div class="flex items-center justify-between">
        <AppLogo mode="wordmark" :height="30" />

        <button
          type="button"
          @click="toggleLang"
          class="relative w-14 h-7 shrink-0 bg-surface-chip rounded-full border border-line-card flex items-center px-1"
        >
          <div
            class="absolute top-1 bottom-1 w-6 bg-surface-card rounded-full shadow flex items-center justify-center transition-all duration-300"
            :class="lang === 'TH' ? 'translate-x-0' : 'translate-x-7'"
          >
            <span class="text-[9px] font-black text-ink-brand">{{ lang }}</span>
          </div>
        </button>
      </div>

      <div class="mt-8 flex items-center gap-2">
        <span class="inline-flex items-center px-3 py-1 rounded-full border border-line-card text-xs font-bold text-ink-card-muted">
          {{ t('reg_tag_portal', 'สมัครเป็นตัวแทน', 'Become an Agent') }}
        </span>
        <span v-if="stepLabel" class="inline-flex items-center px-3 py-1 rounded-full border border-line-card text-xs font-bold text-ink-card-muted">
          {{ stepLabel }}
        </span>
      </div>

      <div class="mt-4">
        <h1 class="text-3xl sm:text-4xl leading-tight text-ink-card">
          <span class="font-light text-ink-card-muted" :class="lang === 'EN' ? 'italic' : ''">{{ t('reg_hello', 'สมัคร', 'Create your') }}</span>
          <span class="font-bold"> {{ t('reg_back', 'สมาชิกใหม่', 'account') }}</span>
        </h1>
        <p class="mt-2 text-sm text-ink-card-muted">{{ introLine }}</p>
      </div>

      <!-- TASK-116 — while ?ref= is being resolved. Without this the invite
           step paints for one frame and is then swapped out, which reads as
           a glitch on a phone; worse, a recruit could start typing an invite
           code they were never asked for. -->
      <div v-if="resolvingRef" class="mt-8 flex items-center gap-3 py-6">
        <Icon name="refresh" :size="18" class="animate-spin text-ink-card-subtle shrink-0" />
        <p class="text-sm text-ink-card-muted">
          {{ t('reg_ref_checking', 'กำลังตรวจสอบลิงก์ชวนเข้าทีม...', 'Checking your team invite link...') }}
        </p>
      </div>

      <!-- STEP 1: invite code -->
      <form v-else-if="step === 'invite'" class="mt-8 space-y-4" @submit.prevent="submitInviteCode" novalidate>
        <!-- TASK-116 — the ?ref= fallback explanation. Rendered as an
             informational note, not an error: the recruit did nothing wrong,
             and the sentence's job is to tell them how to continue. -->
        <div
          v-if="refFallbackMessage"
          class="flex items-start gap-2 rounded-xl bg-surface-warning border border-line-card px-3 py-2.5 text-sm text-ink-warning"
        >
          <Icon name="info" :size="16" class="mt-0.5 shrink-0" />
          <span>{{ refFallbackMessage }}</span>
        </div>

        <div
          v-if="inviteCodeError"
          class="flex items-start gap-2 rounded-xl bg-surface-danger border border-rose-100 px-3 py-2.5 text-sm text-ink-danger"
        >
          <Icon name="alert" :size="16" class="mt-0.5 shrink-0" />
          <span>{{ inviteCodeError }}</span>
        </div>

        <div>
          <label for="invite_code" class="block text-xs font-bold text-ink-card-muted mb-1.5">
            {{ t('reg_invite_code', 'รหัสเชิญ', 'Invite code') }}
            <span class="text-ink-danger">*</span>
          </label>
          <div class="relative">
            <Icon name="key" :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-card-subtle" />
            <input
              id="invite_code"
              ref="inviteCodeInputEl"
              v-model="inviteCode"
              type="text"
              autocomplete="off"
              class="bg-surface-input w-full pl-9 pr-3 py-2.5 rounded-xl border text-sm text-ink-input placeholder:text-ink-input-placeholder focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 transition-colors"
              :class="inviteCodeError ? 'border-rose-400' : 'border-line-input'"
              placeholder="THAILIFE-2026"
              @input="inviteCodeError = ''"
            />
          </div>
        </div>

        <button
          type="submit"
          :disabled="resolvingCode"
          class="w-full py-2.5 rounded-full bg-brand-600 text-ink-primary text-sm font-bold shadow-sm hover:bg-brand-700 transition-colors disabled:opacity-60 disabled:cursor-not-allowed inline-flex items-center justify-center gap-2"
        >
          <span>{{ resolvingCode ? t('reg_checking', 'กำลังตรวจสอบ...', 'Checking...') : t('reg_continue', 'ถัดไป', 'Continue') }}</span>
          <Icon v-if="!resolvingCode" name="arrow_right" :size="16" />
        </button>

        <p class="text-center text-xs text-ink-card-subtle">
          {{ t('reg_have_account', 'มีบัญชีอยู่แล้ว?', 'Already have an account?') }}
          <RouterLink :to="{ name: 'login' }" class="font-bold text-ink-brand hover:underline">
            {{ t('reg_login_link', 'เข้าสู่ระบบ', 'Sign in') }}
          </RouterLink>
        </p>
      </form>

      <!-- STEP 2: registration form -->
      <form v-else-if="step === 'form'" class="mt-8 space-y-4" @submit.prevent="submitRegister" novalidate>
        <!-- TASK-116 — arrived via a leader's recruit link. Says WHOSE team
             and WHICH company, because that is the whole content of the
             token and the recruit never typed either of them. No "เปลี่ยน"
             affordance: there is nothing here the recruit chose or can
             change — the link decided it. -->
        <div
          v-if="viaRecruitLink"
          class="flex items-start gap-2 rounded-xl bg-surface-success border border-emerald-100 px-3 py-2.5 text-sm text-ink-success"
        >
          <Icon name="users" :size="16" class="mt-0.5 shrink-0" />
          <span>
            {{ t(
              'reg_ref_context',
              `คุณกำลังสมัครเข้าทีมของ ${refInviterName} ที่ ${refCompanyName}`,
              `You are joining ${refInviterName}'s team at ${refCompanyName}`,
            ) }}
          </span>
        </div>

        <div v-else class="flex items-center justify-between rounded-xl bg-surface-success border border-emerald-100 px-3 py-2.5 text-sm text-ink-success">
          <span class="inline-flex items-center gap-2">
            <Icon name="building" :size="16" class="shrink-0" />
            {{ resolvedCompanyName }}
          </span>
          <button type="button" @click="changeInviteCode" class="text-xs font-bold text-ink-success/70 hover:text-ink-success underline shrink-0">
            <!--
              TASK-233 (UAT) — "เปลี่ยนรหัส" is wrong for somebody who
              arrived on /c/<code> and never typed a code. The escape hatch
              itself is still right — they may have been sent the wrong
              company's link — so only the words change.
            -->
            {{ viaCompanyLink
              ? t('reg_wrong_company', 'ไม่ใช่บริษัทนี้?', 'Not this company?')
              : t('reg_change_code', 'เปลี่ยนรหัส', 'Change code') }}
          </button>
        </div>

        <div
          v-if="errorMessage"
          class="flex items-start gap-2 rounded-xl bg-surface-danger border border-rose-100 px-3 py-2.5 text-sm text-ink-danger"
        >
          <Icon name="alert" :size="16" class="mt-0.5 shrink-0" />
          <span>{{ errorMessage }}</span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label for="first_name" class="block text-xs font-bold text-ink-card-muted mb-1.5">
              {{ t('reg_first_name', 'ชื่อ', 'First name') }}
              <span class="text-ink-danger">*</span>
            </label>
            <input
              id="first_name"
              ref="firstNameInputEl"
              v-model="form.first_name"
              type="text"
              autocomplete="given-name"
              class="bg-surface-input w-full px-3 py-2.5 rounded-xl border text-sm text-ink-input placeholder:text-ink-input-placeholder focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 transition-colors"
              :class="firstNameError ? 'border-rose-400' : 'border-line-input'"
              @input="firstNameError = ''"
            />
            <p v-if="firstNameError" class="text-xs text-ink-danger mt-1">{{ firstNameError }}</p>
          </div>

          <div>
            <label for="last_name" class="block text-xs font-bold text-ink-card-muted mb-1.5">
              {{ t('reg_last_name', 'นามสกุล', 'Last name') }}
              <span class="text-ink-danger">*</span>
            </label>
            <input
              id="last_name"
              ref="lastNameInputEl"
              v-model="form.last_name"
              type="text"
              autocomplete="family-name"
              class="bg-surface-input w-full px-3 py-2.5 rounded-xl border text-sm text-ink-input placeholder:text-ink-input-placeholder focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 transition-colors"
              :class="lastNameError ? 'border-rose-400' : 'border-line-input'"
              @input="lastNameError = ''"
            />
            <p v-if="lastNameError" class="text-xs text-ink-danger mt-1">{{ lastNameError }}</p>
          </div>
        </div>

        <div>
          <label for="email" class="block text-xs font-bold text-ink-card-muted mb-1.5">
            {{ t('reg_email', 'อีเมล', 'Email') }}
            <span class="text-ink-danger">*</span>
          </label>
          <div class="relative">
            <Icon name="mail" :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-card-subtle" />
            <input
              id="email"
              ref="emailInputEl"
              v-model="form.email"
              type="email"
              autocomplete="username"
              class="bg-surface-input w-full pl-9 pr-9 py-2.5 rounded-xl border text-sm text-ink-input placeholder:text-ink-input-placeholder focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 transition-colors"
              :class="emailError || emailTaken ? 'border-rose-300' : 'border-line-input'"
              placeholder="agent@thailife.test"
              :aria-invalid="emailTaken ? 'true' : undefined"
              aria-describedby="email_status"
              @input="onEmailInput"
              @blur="onEmailBlur"
            />
            <!-- Quiet, and only while a check is actually in flight. A
                 spinner that appears on every keystroke reads as the form
                 struggling; this one appears once, after typing stops. -->
            <span
              v-if="checkingEmail"
              class="absolute right-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 rounded-full border-2 border-line-input border-t-brand-500 animate-spin"
              aria-hidden="true"
            />
          </div>
          <!-- aria-live so the verdict is ANNOUNCED. It arrives without the
               person doing anything, so a screen-reader user would otherwise
               be told nothing at all until submit. -->
          <div id="email_status" aria-live="polite">
            <span v-if="emailError" class="block text-xs text-ink-danger mt-1">{{ emailError }}</span>
            <!--
              THE POINT OF THE WHOLE FEATURE IS THE LINK, NOT THE WARNING.
              "Already registered" on a signup form is not news the person
              can act on by itself — the thing they came to do is get into
              their account. Telling them without offering the door is a
              dead end dressed up as helpfulness.
            -->
            <span v-else-if="emailTaken" class="block text-xs text-ink-danger mt-1">
              {{ t('reg_email_taken', 'อีเมลนี้มีบัญชีในระบบแล้ว', 'This email already has an account') }}
              <!-- No ?email= on this link. It would put a real person's
                   address into browser history, into the Referer header of
                   everything the login page loads, and into any access log
                   in between — for the sake of saving one field of typing
                   (§6, PDPA). -->
              <RouterLink :to="{ name: 'login' }" class="font-bold underline">
                {{ t('reg_email_taken_login', 'เข้าสู่ระบบ', 'Log in') }}
              </RouterLink>
            </span>
          </div>
        </div>

        <div>
          <label for="phone" class="block text-xs font-bold text-ink-card-muted mb-1.5">
            {{ t('reg_phone', 'เบอร์โทร (ไม่บังคับ)', 'Phone (optional)') }}
          </label>
          <div class="relative">
            <Icon name="phone" :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-card-subtle" />
            <input
              id="phone"
              v-model="form.phone"
              type="tel"
              autocomplete="tel"
              class="bg-surface-input w-full pl-9 pr-3 py-2.5 rounded-xl border border-line-input text-sm text-ink-input placeholder:text-ink-input-placeholder focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 transition-colors"
              placeholder="08xxxxxxxx"
            />
          </div>
        </div>

        <!-- TASK-123 (backend TASK-122) — identity document. Two controls,
             always adjacent: the TYPE decides what the NUMBER means, so
             separating them would let someone answer the second question
             without having seen the first. -->
        <div>
          <label class="block text-xs font-bold text-ink-card-muted mb-1.5">
            {{ t('reg_id_type', 'เอกสารยืนยันตัวตน', 'ID document') }}
            <span class="text-ink-danger">*</span>
          </label>
          <!-- One short line on WHY, in plain language. Not a legal notice:
               a consent/PDPA wall on a signup form is read by nobody and
               makes an ordinary question look alarming. -->
          <p class="text-xs text-ink-card-subtle mb-2">
            {{ t('reg_id_why', 'ใช้เพื่อยืนยันตัวตนของตัวแทนเท่านั้น', 'Used only to verify your identity as an agent.') }}
          </p>
          <div class="grid grid-cols-2 gap-2">
            <button
              v-for="opt in idDocumentTypeOptions"
              :key="opt.value"
              type="button"
              :aria-pressed="form.id_document_type === opt.value"
              class="min-h-[44px] px-3 py-2.5 rounded-xl border text-sm font-bold transition-colors"
              :class="
                form.id_document_type === opt.value
                  ? 'bg-brand-600 border-brand-600 text-ink-primary'
                  : 'bg-surface-card border-line-card text-ink-card-muted hover:border-brand-500'
              "
              @click="selectIdDocumentType(opt.value)"
            >
              {{ opt.label }}
            </button>
          </div>
          <p v-if="idDocumentTypeError" class="text-xs text-ink-danger mt-1">{{ idDocumentTypeError }}</p>
        </div>

        <div>
          <label for="national_id" class="block text-xs font-bold text-ink-card-muted mb-1.5">
            {{ documentNumberLabel }}
            <span class="text-ink-danger">*</span>
          </label>
          <!-- A Thai ID gets the card's own five groups (see
               NationalIdSegments.vue). A passport is one free-form field:
               its number has no printed grouping to mirror, and splitting a
               6-12 character alphanumeric into fixed boxes would invent a
               format that does not exist. -->
          <NationalIdSegments
            v-if="isThaiIdDocument"
            id="national_id"
            ref="nationalIdSegmentsEl"
            v-model="form.national_id"
            :invalid="Boolean(nationalIdError)"
            :aria-label="documentNumberLabel"
            @update:model-value="nationalIdError = ''"
          />
          <div v-else class="relative">
            <Icon name="shield" :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-card-subtle" />
            <!-- autocomplete off + spellcheck off: this is PDPA-sensitive
                 (Section 6) and has no business being remembered by the
                 browser's form-fill store or sent to a spell checker. -->
            <input
              id="national_id"
              ref="nationalIdInputEl"
              v-model="form.national_id"
              type="text"
              autocomplete="off"
              spellcheck="false"
              inputmode="text"
              autocapitalize="characters"
              maxlength="12"
              :placeholder="documentNumberPlaceholder"
              class="bg-surface-input w-full pl-9 pr-3 py-2.5 min-h-[44px] rounded-xl border text-sm text-ink-input placeholder:text-ink-input-placeholder placeholder:normal-case focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 transition-colors uppercase tracking-wide"
              :class="nationalIdError ? 'border-rose-400' : 'border-line-input'"
              @input="nationalIdError = ''"
            />
          </div>
          <p class="text-xs text-ink-card-subtle mt-1">{{ documentNumberHint }}</p>
          <p v-if="nationalIdError" class="text-xs text-ink-danger mt-1">{{ nationalIdError }}</p>
        </div>

        <div>
          <label for="password" class="block text-xs font-bold text-ink-card-muted mb-1.5">
            {{ t('reg_password', 'รหัสผ่าน', 'Password') }}
            <span class="text-ink-danger">*</span>
          </label>
          <div class="relative">
            <Icon name="key" :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-card-subtle" />
            <input
              id="password"
              ref="passwordInputEl"
              v-model="form.password"
              :type="showPassword ? 'text' : 'password'"
              autocomplete="new-password"
              class="bg-surface-input w-full pl-9 pr-10 py-2.5 rounded-xl border text-sm text-ink-input placeholder:text-ink-input-placeholder focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 transition-colors"
              :class="passwordError ? 'border-rose-400' : 'border-line-input'"
              placeholder="••••••••"
              @input="passwordError = ''"
            />
            <button
              type="button"
              @click="showPassword = !showPassword"
              class="absolute right-1.5 top-1/2 -translate-y-1/2 w-7 h-7 flex items-center justify-center rounded-full text-ink-card-subtle hover:bg-surface-chip hover:text-ink-card-muted transition-colors"
              :title="showPassword ? t('hide', 'ซ่อน', 'Hide') : t('show', 'แสดง', 'Show')"
            >
              <Icon :name="showPassword ? 'eye_off' : 'eye'" :size="16" />
            </button>
          </div>
          <p v-if="passwordError" class="text-xs text-ink-danger mt-1">{{ passwordError }}</p>
        </div>

        <div>
          <label for="password_confirmation" class="block text-xs font-bold text-ink-card-muted mb-1.5">
            {{ t('reg_password_confirm', 'ยืนยันรหัสผ่าน', 'Confirm password') }}
            <span class="text-ink-danger">*</span>
          </label>
          <div class="relative">
            <Icon name="key" :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-card-subtle" />
            <input
              id="password_confirmation"
              ref="passwordConfirmInputEl"
              v-model="form.password_confirmation"
              :type="showPasswordConfirm ? 'text' : 'password'"
              autocomplete="new-password"
              class="bg-surface-input w-full pl-9 pr-10 py-2.5 rounded-xl border text-sm text-ink-input placeholder:text-ink-input-placeholder focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 transition-colors"
              :class="passwordConfirmError ? 'border-rose-400' : 'border-line-input'"
              placeholder="••••••••"
              @input="passwordConfirmError = ''"
            />
            <button
              type="button"
              @click="showPasswordConfirm = !showPasswordConfirm"
              class="absolute right-1.5 top-1/2 -translate-y-1/2 w-7 h-7 flex items-center justify-center rounded-full text-ink-card-subtle hover:bg-surface-chip hover:text-ink-card-muted transition-colors"
              :title="showPasswordConfirm ? t('hide', 'ซ่อน', 'Hide') : t('show', 'แสดง', 'Show')"
            >
              <Icon :name="showPasswordConfirm ? 'eye_off' : 'eye'" :size="16" />
            </button>
          </div>
          <p v-if="passwordConfirmError" class="text-xs text-ink-danger mt-1">{{ passwordConfirmError }}</p>
        </div>

        <button
          type="submit"
          :disabled="submitting"
          class="w-full py-2.5 rounded-full bg-brand-600 text-ink-primary text-sm font-bold shadow-sm hover:bg-brand-700 transition-colors disabled:opacity-60 disabled:cursor-not-allowed inline-flex items-center justify-center gap-2"
        >
          <span>{{ submitting ? t('reg_submitting', 'กำลังสมัคร...', 'Registering...') : t('reg_submit', 'สมัครสมาชิก', 'Create account') }}</span>
          <Icon v-if="!submitting" name="arrow_right" :size="16" />
        </button>
      </form>

      <!-- STEP 3: done — "รอการอนุมัติ", NOT "now go and sign in".
           TASK-116 point 5. Before TASK-115 the login gate did not exist, so
           dropping a fresh registrant on /login was harmless. It is not any
           more: an unverified/pending account now gets a 403 there. Pushing
           them straight at a screen that will reject them is the definition
           of a dead end, so this state OWNS the waiting instead — it names
           the two things that must happen, in order, says which one is
           theirs to do, and demotes the sign-in link to something they use
           AFTER approval rather than the obvious next tap. -->
      <div v-else class="mt-8 py-4">
        <div class="mx-auto w-14 h-14 rounded-full border border-amber-100 flex items-center justify-center">
          <Icon name="clock" :size="24" class="text-ink-warning" />
        </div>
        <h2 class="mt-4 text-lg font-bold text-ink-card text-center">
          {{ t('reg_done_title', 'สมัครสำเร็จ — รอการอนุมัติ', 'Registered — waiting for approval') }}
        </h2>
        <p class="mt-2 text-sm text-ink-card-muted leading-relaxed text-center">
          {{ t(
            'reg_done_body',
            'บัญชีของคุณถูกสร้างแล้ว แต่ยังเข้าสู่ระบบไม่ได้จนกว่าจะครบ 2 ขั้นตอนนี้',
            'Your account has been created, but you cannot sign in until both of these are done.',
          ) }}
        </p>

        <ol class="mt-5 space-y-3 text-left">
          <li class="flex items-start gap-3 rounded-xl border border-line-card px-3 py-3">
            <span class="shrink-0 w-6 h-6 rounded-full bg-surface-chip text-ink-chip text-xs font-bold flex items-center justify-center">1</span>
            <div class="min-w-0">
              <p class="text-sm font-bold text-ink-card">
                {{ t('reg_done_step1', 'ยืนยันอีเมลของคุณ', 'Verify your email') }}
              </p>
              <p class="text-xs text-ink-card-muted mt-0.5 leading-relaxed">
                {{ t(
                  'reg_done_step1_body',
                  `เราส่งลิงก์ยืนยันไปที่ ${form.email} แล้ว กรุณาเปิดอีเมลแล้วคลิกลิงก์ — ขั้นตอนนี้คุณทำเองได้เลย`,
                  `We sent a verification link to ${form.email}. Open it and click the link — this step is yours to do.`,
                ) }}
              </p>
            </div>
          </li>
          <li class="flex items-start gap-3 rounded-xl border border-line-card px-3 py-3">
            <span class="shrink-0 w-6 h-6 rounded-full bg-surface-chip text-ink-chip text-xs font-bold flex items-center justify-center">2</span>
            <div class="min-w-0">
              <p class="text-sm font-bold text-ink-card">
                {{ t('reg_done_step2', 'รอการอนุมัติ', 'Wait for approval') }}
              </p>
              <!-- Names the leader when we know them: on the recruit-link
                   path that person is the one who will actually press the
                   button (ADR-025 §7), and "รอ <ชื่อ>" is far more use than
                   "รอบริษัท" to someone who has never met the admin. -->
              <p class="text-xs text-ink-card-muted mt-0.5 leading-relaxed">
                {{ viaRecruitLink
                  ? t(
                      'reg_done_step2_ref',
                      `จากนั้น ${refInviterName} หรือผู้ดูแลระบบของ ${refCompanyName} จะอนุมัติบัญชีของคุณ เราจะแจ้งให้ทราบทางอีเมล`,
                      `${refInviterName} or an administrator at ${refCompanyName} will then approve your account. We'll let you know by email.`,
                    )
                  : t(
                      'reg_done_step2_code',
                      'จากนั้นผู้ดูแลระบบของบริษัทจะอนุมัติบัญชีของคุณ เราจะแจ้งให้ทราบทางอีเมล',
                      'An administrator at your company will then approve your account. We\'ll let you know by email.',
                    ) }}
              </p>
            </div>
          </li>
        </ol>

        <p class="mt-6 text-center text-xs text-ink-card-subtle">
          {{ t('reg_done_after', 'เมื่อได้รับอนุมัติแล้ว', 'Once approved,') }}
          <RouterLink :to="{ name: 'login' }" class="font-bold text-ink-brand hover:underline">
            {{ t('reg_login_link', 'เข้าสู่ระบบ', 'sign in') }}
          </RouterLink>
        </p>
      </div>
    </div>
  </div>
</template>
