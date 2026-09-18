/**
 * 2026-09-08 (human: "อยากทำ soft delete ในการลบผู้สมัคร ที่ยังไม่ยืนยัน
 * ด้วยสิทธิ์ Super Admin และ Admin Company").
 *
 * The soft delete already existed. What did not was any way to reach it from
 * the screen the junk sign-ups are on: the roster row offered "แก้ไข" alone,
 * and the deactivate control lived five sections down inside a 1,700-line
 * editor written for a trading agent.
 *
 * These tests are almost entirely about WHERE THE BUTTON DOES NOT APPEAR.
 * Offering the delete is the easy half; every false positive is a red button
 * over a working agent's name, and the roster is the one screen where every
 * agent in the company is listed together.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const post = vi.fn()
const put = vi.fn()
const del = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: (...args: unknown[]) => put(...args),
    delete: (...args: unknown[]) => del(...args),
    patch: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: class extends Error {},
}))

vi.mock('vue-router', async () => {
  const actual = await vi.importActual<typeof import('vue-router')>('vue-router')

  return { ...actual, useRoute: () => ({ query: {} }), useRouter: () => ({ push: vi.fn(), replace: vi.fn() }) }
})

vi.mock('@/utils/qrCode', () => ({ generateQrDataUrl: vi.fn().mockResolvedValue('') }))

import AgentRosterView from '../AgentRosterView.vue'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'

const ALL = { update: true, deactivate: true, restore: true, move_company: true }

const COMPANIES = [{ id: 4, name: 'ไทยประกันชีวิต', slug: 'tli' }]

/** The two rows from the human's screenshot: signed up, never confirmed. */
function applicant(over: Record<string, unknown> = {}) {
  return {
    id: 7,
    name: 'ทดสอบสมัครใหม่ นามสกุล',
    first_name: 'ทดสอบสมัครใหม่',
    last_name: 'นามสกุล',
    email: 'ikenyaa+4321568@gmail.com',
    phone: null,
    role: 'agent',
    company: { id: 4, name: 'GENESENN' },
    has_passed_basic_cert: false,
    is_active: true,
    is_unconfirmed_applicant: true,
    agent_approval_status: 'pending',
    registered_via: 'email',
    is_team_leader: false,
    created_at: '2026-09-01T00:00:00Z',
    permissions: { ...ALL },
    /*
     * 2026-09-18 — an EMPTY array, meaning "nothing is in the way". The
     * distinction the screen depends on is empty vs UNDEFINED: undefined
     * means the server was never asked, which must never render as
     * removable (see the fail-closed test below).
     */
    removal_blockers: [],
    ...over,
  }
}

function mockRoster(rows: unknown[]) {
  get.mockImplementation(async (path: string) => {
    const p = String(path)
    if (p.startsWith('/companies')) return { data: COMPANIES }
    if (p.startsWith('/cert-tiers')) return { data: [] }
    if (p.startsWith('/user-certifications')) return { data: [] }
    if (p.startsWith('/agent-invite-links')) return { data: [] }
    if (p.startsWith('/users')) return { data: rows }

    return { data: [] }
  })
}

/*
 * 2026-09-18 — mounted views are UNMOUNTED after every test now.
 *
 * RowActions teleports its panel to <body>, so a view left mounted leaves a
 * live menu behind. `document.querySelector` then finds the PREVIOUS test's
 * panel, and the assertion reads a stale row — which fails with a message
 * about the wrong label and sends you looking in entirely the wrong place.
 */
let mounted: ReturnType<typeof mount>[] = []

afterEach(() => {
  mounted.forEach((w) => w.unmount())
  mounted = []
})

async function mountView() {
  const wrapper = mount(AgentRosterView, {
    global: {
      stubs: {
        // The ใช้งานอยู่ / ปิดใช้งาน tabs live in HeroHeader's `tabs` slot, and
        // a stub that renders only the default slot swallows them — every
        // "switch to the ปิดใช้งาน tab" test would then find no button.
        HeroHeader: { template: '<div><slot name="actions" /><slot name="tabs" /><slot /></div>' },
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
        CompanyScopeNotice: true,
        AgentEditModal: true,
        SuccessDialog: true,
        ConfirmDialog: {
          name: 'ConfirmDialog',
          props: ['show', 'title', 'body', 'confirmLabel', 'variant', 'busy'],
          template:
            '<div v-if="show" data-test="confirm"><p>{{ title }}</p><p>{{ body }}</p>'
            + '<button data-test="confirm-yes" @click="$emit(\'confirm\')">ok</button></div>',
        },
        RouterLink: { props: ['to'], template: '<a><slot /></a>' },
      },
    },
  })
  await flushPromises()
  mounted.push(wrapper)

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountView>>

const at = (w: Wrapper, test: string) => w.find(`[data-test="${test}"]`)

/*
 * 2026-09-18 — the row's secondary actions moved behind a ⋯ menu
 * (RowActions), and that menu is TELEPORTED to <body> so a horizontally
 * scrolling table cannot clip it. Two consequences for every test below that
 * reaches for one of them:
 *
 *   • it has to open the menu first — which is the point of the change, and
 *     a test that did not would not be testing what an admin does;
 *   • it has to look in `document`, not in the wrapper, because the panel is
 *     no longer inside the component's own tree.
 *
 * `approve-applicant` and `restore-applicant` are NOT in here: they stay on
 * the row as the one prominent action, so they are still found with `at()`.
 */
async function openMenu(w: Wrapper) {
  await at(w, 'row-menu').trigger('click')
  await flushPromises()
}

const inMenu = (test: string) => document.querySelector<HTMLButtonElement>(`[data-test="${test}"]`)

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  put.mockReset()
  del.mockReset()
  post.mockResolvedValue({ data: {} })
  put.mockResolvedValue({ data: {} })
  del.mockResolvedValue(undefined)
  mockRoster([applicant()])

  const auth = useAuthStore()
  auth.user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
  useActiveCompanyStore().setCompany(null)
})

describe('AgentRosterView — removing a sign-up that never completed', () => {
  it('asks the server which rows this admin may act on', async () => {
    /*
     * Without `with_permissions=1` the only options are hiding the buttons
     * from everyone or rendering them and letting the 403 be the answer.
     * TASK-245 closed that door; this keeps it closed.
     */
    await mountView()

    expect(get.mock.calls.some(([p]) => String(p).includes('with_permissions=1'))).toBe(true)
  })

  it('offers the delete on an unfinished sign-up', async () => {
    const wrapper = await mountView()
    await openMenu(wrapper)

    expect(inMenu('remove-member')).not.toBeNull()
    expect(inMenu('remove-member')!.disabled).toBe(false)
  })

  it('offers it on an approved member who has never done anything', async () => {
    /*
     * 2026-09-18 (human: "หากไม่มีกิจกรรม การซื้อขายอะไร ให้สามารถลบ รายชื่อ
     * สมาชิก แบบ Soft Delete ได้"). Until then the button only ever appeared
     * on a sign-up that never completed, so an admin-created account that
     * was never used could only be switched off, never cleared away.
     */
    mockRoster([applicant({ is_unconfirmed_applicant: false, agent_approval_status: 'approved' })])

    const wrapper = await mountView()
    await openMenu(wrapper)

    expect(inMenu('remove-member')).not.toBeNull()
    expect(inMenu('remove-member')!.textContent).toContain('ลบสมาชิก')
  })

  it('shows the delete DISABLED, with a reason, on a member who has history', async () => {
    /*
     * The one that matters most, and the owner's own answer on how it should
     * look ("แสดงแต่กดไม่ได้ + บอกเหตุผล"). Hiding it was the alternative:
     * an admin who cannot see the control concludes the feature does not
     * exist, where one who sees it greyed out with "มีลูกค้า 3 ราย" has
     * learned something true about the account.
     */
    mockRoster([applicant({
      is_unconfirmed_applicant: false,
      agent_approval_status: 'approved',
      removal_blockers: [{ key: 'clients', count: 3 }, { key: 'commission', count: 2 }],
    })])

    const wrapper = await mountView()

    // The reason is on the ROW, readable without opening anything — the menu
    // repeats it next to the dead item for whoever opens it anyway.
    await openMenu(wrapper)
    expect(inMenu('remove-member')!.disabled).toBe(true)

    const reason = at(wrapper, 'removal-blocked-reason').text()
    expect(reason).toContain('ลูกค้าที่แนะนำ 3 ราย')
    expect(reason).toContain('ค่าแนะนำ 2 รายการ')
    // …and it points at what to do instead, rather than only refusing.
    expect(reason).toContain('ปิดใช้งาน')
  })

  it('does NOT offer it when the server never answered the activity question', async () => {
    /*
     * Fail closed, the same way the permissions object does. `undefined` is
     * "nobody asked", not "nothing is in the way" — and an omission must
     * never be the thing that enables a delete.
     */
    mockRoster([applicant({ removal_blockers: undefined })])

    const wrapper = await mountView()
    await openMenu(wrapper)

    expect(inMenu('remove-member')).toBeNull()
  })

  it('does NOT offer it when the server says this admin may not', async () => {
    // The flag says "this row is removable in principle"; the Policy says
    // "by you". Both are required, and neither is re-derived here.
    mockRoster([applicant({ permissions: { ...ALL, deactivate: false } })])

    const wrapper = await mountView()
    await openMenu(wrapper)

    expect(inMenu('remove-member')).toBeNull()
  })

  it('does NOT offer it when the server sent no permissions at all', async () => {
    /*
     * Fail closed. A missing `permissions` object means the request did not
     * ask for them (or an older API answered) — that is "unknown", and
     * unknown must never render as allowed.
     */
    mockRoster([applicant({ permissions: undefined })])

    const wrapper = await mountView()
    await openMenu(wrapper)

    expect(inMenu('remove-member')).toBeNull()
  })

  it('warns by name and promises the row comes back', async () => {
    /*
     * A soft delete the screen gives no way to undo is a hard delete with
     * better paperwork. The dialog has to say both: whose row this is, and
     * that it is recoverable — otherwise a reversible action reads as an
     * irreversible one and nobody uses it.
     */
    const wrapper = await mountView()
    await openMenu(wrapper)
    inMenu('remove-member')!.click()
    await flushPromises()

    const body = at(wrapper, 'confirm').text()
    expect(body).toContain('ทดสอบสมัครใหม่ นามสกุล')
    expect(body).toContain('กู้คืน')
  })

  it('says the email is released, and what that costs', async () => {
    /*
     * 2026-09-18 — THIS TEST USED TO ASSERT THE OPPOSITE ("สมัครใหม่ด้วย
     * อีเมลเดิมไม่ได้"), which was the true and unwelcome consequence of
     * `unique:users,email` seeing soft-deleted rows. The owner asked for the
     * address to be released, so the copy changed with the behaviour.
     *
     * The second half is the new consequence and is not optional: a released
     * address can be taken by somebody else, and then กู้คืน cannot put the
     * account back. An admin should hear that here, not from a refusal weeks
     * later.
     */
    const wrapper = await mountView()
    await openMenu(wrapper)
    inMenu('remove-member')!.click()
    await flushPromises()

    const body = at(wrapper, 'confirm').text()
    expect(body).toContain('ikenyaa+4321568@gmail.com')
    expect(body).toContain('ปลด')
    expect(body).toContain('กู้คืนบัญชีนี้ไม่ได้')
  })

  it('says the consequences without naming a column or a status code', async () => {
    /*
     * The human's actual instruction: "แจ้งเตือนผู้ใช้ถึงผลกระทบเป็นภาษาคน
     * เข้าใจ ไม่เอาภาษาระบบ". This is a cheap guard, but it is the one that
     * catches the drift back to developer vocabulary the next time somebody
     * edits this copy.
     */
    const wrapper = await mountView()
    await openMenu(wrapper)
    inMenu('remove-member')!.click()
    await flushPromises()

    const body = at(wrapper, 'confirm').text()
    for (const jargon of ['deleted_at', 'soft delete', 'is_active', 'pending', 'null', '422']) {
      expect(body.toLowerCase()).not.toContain(jargon.toLowerCase())
    }
  })

  it('sends the delete only after the confirm', async () => {
    const wrapper = await mountView()
    await openMenu(wrapper)
    inMenu('remove-member')!.click()
    await flushPromises()

    expect(del).not.toHaveBeenCalled()

    await at(wrapper, 'confirm-yes').trigger('click')
    await flushPromises()

    /*
     * `release_email` says this was a REMOVAL, not the switch-off the edit
     * modal performs against the same endpoint. The server re-checks that
     * the account is untouched before honouring it, so the flag widens
     * nothing on its own — but without it the address would stay locked and
     * the dialog above would have lied.
     */
    expect(del).toHaveBeenCalledWith('/users/7', { release_email: true })
  })
})

describe('AgentRosterView — deciding on a registration from the list', () => {
  it('offers อนุมัติ and ไม่อนุมัติ on a row that is waiting', async () => {
    /*
     * The report: the row already SAYS "รออนุมัติ", and until now the only
     * way to act on what it said was to leave the page.
     */
    mockRoster([applicant({ permissions: { ...ALL, approve_registration: true, reject_registration: true } })])

    const wrapper = await mountView()

    // อนุมัติ stays on the row as the one prominent action; ไม่อนุมัติ moved
    // into the menu deliberately — rejection writes a permanent negative
    // record the registrant is shown by name, and that does not belong under
    // a hurried cursor.
    expect(at(wrapper, 'approve-applicant').exists()).toBe(true)
    await openMenu(wrapper)
    expect(inMenu('reject-applicant')).not.toBeNull()
  })

  it('offers neither on somebody who is already approved', async () => {
    mockRoster([applicant({
      agent_approval_status: 'approved',
      is_unconfirmed_applicant: false,
      permissions: { ...ALL, approve_registration: true, reject_registration: true },
    })])

    const wrapper = await mountView()

    expect(at(wrapper, 'approve-applicant').exists()).toBe(false)
    expect(at(wrapper, 'reject-applicant').exists()).toBe(false)
  })

  it('can offer approve without offering reject', async () => {
    /*
     * Not a hypothetical: a team leader may approve their own recruits
     * (ADR-025 §7) and may never reject — rejection writes a permanent
     * negative record the registrant is shown by name. One combined "may
     * decide" flag would have handed them the wrong button.
     */
    mockRoster([applicant({ permissions: { ...ALL, approve_registration: true, reject_registration: false } })])

    const wrapper = await mountView()

    expect(at(wrapper, 'approve-applicant').exists()).toBe(true)
    expect(at(wrapper, 'reject-applicant').exists()).toBe(false)
  })

  it('approves through the same endpoint the queue uses', async () => {
    mockRoster([applicant({ permissions: { ...ALL, approve_registration: true } })])

    const wrapper = await mountView()
    await at(wrapper, 'approve-applicant').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/agent-approvals/7/approve')
  })

  it('sends the approval once even when the button is pressed twice', async () => {
    // The bug the approvals queue shipped with this morning, kept out of this
    // screen from the start: the row does not change until the reload lands.
    let release: (v: unknown) => void = () => {}
    put.mockImplementation(() => new Promise((r) => { release = r }))
    mockRoster([applicant({ permissions: { ...ALL, approve_registration: true } })])

    const wrapper = await mountView()
    await at(wrapper, 'approve-applicant').trigger('click')
    await at(wrapper, 'approve-applicant').trigger('click')

    expect(put).toHaveBeenCalledTimes(1)
    release({ data: {} })
    await flushPromises()
  })

  it('asks for a reason before rejecting, and says who will read it', async () => {
    /*
     * The text is optional but it is shown to the applicant verbatim at the
     * login screen. Somebody typing it deserves to know that before they
     * type, not after.
     */
    mockRoster([applicant({ permissions: { ...ALL, reject_registration: true } })])

    const wrapper = await mountView()
    await openMenu(wrapper)
    inMenu('reject-applicant')!.click()
    await flushPromises()

    expect(at(wrapper, 'reject-reason').exists()).toBe(true)
    expect(wrapper.text()).toContain('จะเห็นเหตุผลนี้ตอนพยายามเข้าสู่ระบบ')
    expect(put).not.toHaveBeenCalled()
  })

  it('sends the reason with the rejection', async () => {
    mockRoster([applicant({ permissions: { ...ALL, reject_registration: true } })])

    const wrapper = await mountView()
    await openMenu(wrapper)
    inMenu('reject-applicant')!.click()
    await flushPromises()
    await at(wrapper, 'reject-reason').setValue('ข้อมูลไม่ครบ')
    await at(wrapper, 'reject-confirm').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/agent-approvals/7/reject', { reason: 'ข้อมูลไม่ครบ' })
  })

  it('omits an empty reason rather than sending a blank one', async () => {
    // The column is shown verbatim to the registrant; an empty string there
    // would render as a rejection with a reason that is a blank line.
    mockRoster([applicant({ permissions: { ...ALL, reject_registration: true } })])

    const wrapper = await mountView()
    await openMenu(wrapper)
    inMenu('reject-applicant')!.click()
    await flushPromises()
    await at(wrapper, 'reject-reason').setValue('   ')
    await at(wrapper, 'reject-confirm').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/agent-approvals/7/reject', { reason: undefined })
  })
})

describe('AgentRosterView — putting one back', () => {
  it('says why a removed applicant is in the ปิดใช้งาน tab', async () => {
    /*
     * A removed junk sign-up and a switched-off trading agent land in the
     * same list wearing the same grey. Without this line the tab reads as
     * "agents we let go" and nobody dares empty it.
     */
    mockRoster([applicant({ is_active: false })])

    const wrapper = await mountView()
    await wrapper.findAll('button').find((b) => b.text().includes('ปิดใช้งาน'))!.trigger('click')

    expect(at(wrapper, 'removed-applicant-note').exists()).toBe(true)
  })

  it('offers the undo on the same row the delete was on', async () => {
    mockRoster([applicant({ is_active: false })])

    const wrapper = await mountView()
    await wrapper.findAll('button').find((b) => b.text().includes('ปิดใช้งาน'))!.trigger('click')
    await at(wrapper, 'restore-applicant').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/users/7/restore', {})
  })

  it('does not offer the undo without the server\'s permission', async () => {
    mockRoster([applicant({ is_active: false, permissions: { ...ALL, restore: false } })])

    const wrapper = await mountView()
    await wrapper.findAll('button').find((b) => b.text().includes('ปิดใช้งาน'))!.trigger('click')

    expect(at(wrapper, 'restore-applicant').exists()).toBe(false)
  })
})
