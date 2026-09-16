/**
 * 2026-09-15 — where the company's own commission shows up once it has a
 * seat in its own hierarchy (ขั้นตอนที่ 4.2).
 *
 * Two screens, one rule: the company's share is REAL MONEY that NEVER MOVES.
 * Both halves have to be visible, and they pull in opposite directions —
 * hiding the row loses a number an admin needs, and treating it like any other
 * row queues a transfer that cannot happen.
 *
 *   · The payout list keeps the row and drops its "จ่ายแล้ว" button. Marking
 *     it paid would record a transfer the company made to itself, and a
 *     ledger row cannot be corrected afterwards (BR-4).
 *   · The upline field on a person who reports to the seat becomes a
 *     statement. The seat is not in the picker (the roster is agents only),
 *     so the only edit available there is emptying the field — which stops
 *     the company earning on that person, silently.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const post = vi.fn()
const put = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: (...args: unknown[]) => put(...args),
    patch: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: class extends Error {},
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import AgentEditModal from '../AgentEditModal.vue'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'

const TLI = { id: 4, name: 'Thai Life insurance', slug: 'tli' }

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  put.mockReset()
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
})

/*
 * ── 2026-09-16: THE LEDGER-PANEL TESTS MOVED, AND ONE OF THEM WAS WRONG ──
 *
 * A `describe('the payout list')` block stood here, mounting
 * CommissionLedgerPanel and asserting that the company's own row was marked
 * "ส่วนของบริษัท" and carried the note "เงินอยู่กับบริษัทอยู่แล้ว ไม่ต้องโอน".
 *
 * Both halves are gone for different reasons, and neither quietly:
 *
 *   · THE PANEL. รายรายการ was folded into ตั้งจ่าย (owner: "ผมว่ามันทับซ้อน").
 *     What it uniquely showed — the payout type and whose sale produced an
 *     override — is now two columns of that screen's drill-down, and the
 *     company row's marking is pinned in CommissionPayoutsView.spec.ts.
 *
 *   · THE NOTE WAS FALSE BY THEN. "ไม่ต้องโอน" was true for exactly one day.
 *     The owner then asked for the company's share to be payable
 *     ("ให้เพิ่มทำจ่ายบริษัทให้เลือกได้ด้วย") and it is transferred to a real
 *     company bank account like anybody else's. A test asserting the old
 *     sentence would have kept a contradiction alive on the screen.
 *
 * What remains below is about AgentEditModal, which the seat also changes.
 */
describe('the upline field of somebody who reports to the seat', () => {
  async function mountModal(subject: Record<string, unknown>) {
    get.mockImplementation(async (path: string) => {
      if (String(path).startsWith('/users/')) return { data: subject }
      if (String(path).startsWith('/companies')) return { data: [TLI] }

      return { data: [] }
    })

    const wrapper = mount(AgentEditModal, {
      props: { agentId: 42, roster: [], companies: [TLI] },
      global: { stubs: { Icon: true, ConfirmDialog: true, BuddhistDateInput: true, PlatformScopeBadge: true, LoadingSkeleton: true } },
    })
    await flushPromises()

    return wrapper
  }

  const AGENT = {
    id: 42,
    name: 'สมชาย ใจดี',
    first_name: 'สมชาย',
    last_name: 'ใจดี',
    email: 'somchai@example.com',
    phone: null,
    role: 'agent',
    company: { id: TLI.id, name: TLI.name },
    is_active: true,
    is_team_leader: false,
    manager_id: 90,
    manager: { id: 90, name: 'Thai Life insurance' },
    manager_is_commission_house_account: true,
    is_commission_house_account: false,
    permissions: { update: true, delete: true, deactivate: true, restore: true, move_company: true },
  }

  it('states the seat instead of offering a dropdown that cannot hold it', async () => {
    const wrapper = await mountModal(AGENT)

    const upline = wrapper.get('[data-test="upline-house-account"]')
    expect(upline.text()).toContain('Thai Life insurance')
    expect(upline.text()).toContain('บัญชีบริษัท')
    expect(upline.text()).toContain('เปลี่ยนที่นี่ไม่ได้')
  })

  it('offers no "ไม่มีหัวหน้า" option to empty it with', async () => {
    /*
     * Emptying it takes the company out of its own payout chain for this
     * person — no error, nothing on screen, and the loss only shows up in a
     * payout weeks later. The seat is not in the options either (the roster is
     * agents only), so a select here could do nothing BUT harm.
     */
    const wrapper = await mountModal(AGENT)

    expect(wrapper.get('[data-test="upline-house-account"]').find('select').exists()).toBe(false)
  })

  it('still gives an ordinary agent their dropdown back', async () => {
    const wrapper = await mountModal({
      ...AGENT,
      manager_id: null,
      manager: null,
      manager_is_commission_house_account: false,
    })

    expect(wrapper.find('[data-test="upline-house-account"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('ไม่มีหัวหน้า')
  })
})
