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

import CommissionLedgerPanel from '../CommissionLedgerPanel.vue'
import AgentEditModal from '../AgentEditModal.vue'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'

const TLI = { id: 4, name: 'Thai Life insurance', slug: 'tli' }

function ledgerRow(over: Record<string, unknown> = {}) {
  return {
    id: 1,
    referral: { id: 5, client: { id: 6, name: 'คุณลูกค้า' } },
    agent: { id: 42, name: 'สมชาย' },
    cert_tier_at_time: { id: 1, key: 'basic', name: 'Basic' },
    product: { id: 3, name: 'GENESENN 1-Year Vital Blueprint' },
    rate_type_applied: 'percentage',
    rate_applied: 500,
    amount_satang: 149500,
    payment_status: 'pending',
    earned_via: 'direct',
    override_source_agent: null,
    is_company_share: false,
    paid_at: null,
    created_at: '2026-09-14T00:00:00Z',
    ...over,
  }
}

/** The company's own override row, as the server flags it. */
const COMPANY_ROW = ledgerRow({
  id: 2,
  agent: { id: 90, name: 'Thai Life insurance' },
  cert_tier_at_time: null,
  earned_via: 'override',
  override_source_agent: { id: 42, name: 'สมชาย' },
  amount_satang: 44850,
  is_company_share: true,
})

async function mountPayouts(rows: unknown[]) {
  get.mockImplementation(async (path: string) => {
    if (String(path).startsWith('/companies')) return { data: [TLI] }

    return { data: rows }
  })

  const active = useActiveCompanyStore()
  active.companies = [TLI]
  active.selectedId = TLI.id

  const wrapper = mount(CommissionLedgerPanel, {
    global: {
      stubs: { EmptyState: true, Icon: true, LoadingSkeleton: true },
    },
  })
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  put.mockReset()
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
})

/*
 * 2026-09-15 — mounted as a PANEL now, not a page. The flat ledger moved
 * inside CommissionPayoutsView as its "รายรายการ" view when the two payout
 * menus merged; nothing about what it renders per row changed.
 */
describe('the payout list', () => {
  it('marks the company row and leaves the agent row alone', async () => {
    const wrapper = await mountPayouts([ledgerRow(), COMPANY_ROW])

    expect(wrapper.get('[data-test="company-share-2"]').text()).toBe('ส่วนของบริษัท')
    expect(wrapper.find('[data-test="company-share-1"]').exists()).toBe(false)
  })

  it('offers no way to mark the company row paid', async () => {
    /*
     * The one that matters. "จ่ายแล้ว" records a transfer; the company
     * transferring to itself is not a thing that happens, so the button would
     * only ever create a payment record with no payment behind it — and BR-4
     * means nobody can take it back.
     */
    const wrapper = await mountPayouts([COMPANY_ROW])

    expect(wrapper.text()).not.toContain('กำลังบันทึก')
    expect(wrapper.get('[data-test="company-share-note-2"]').text()).toContain('ไม่ต้องโอน')
    expect(post).not.toHaveBeenCalled()
  })

  it('still offers it on an ordinary pending row', async () => {
    // The control: the guard is keyed on the flag, never on "override" or on
    // the payee's name, and a real leader's override is still a payout.
    const wrapper = await mountPayouts([ledgerRow({ earned_via: 'override' })])

    expect(wrapper.find('[data-test="company-share-note-1"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('จ่ายแล้ว')
  })
})

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
