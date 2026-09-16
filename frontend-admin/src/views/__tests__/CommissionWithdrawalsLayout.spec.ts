/**
 * คำขอเบิกค่าคอม — the page fills the window, and an empty queue explains itself.
 *
 * Owner, 2026-09-15: "หน้านี้ทำอะไร แล้วทำไมข้อมูลไม่ขึ้น ปรับ UI ให้เต็ม screen
 * เหมือนหน้าอื่น ๆ" — one screenshot, two complaints, and the second one is the
 * interesting half.
 *
 * ── THE LAYOUT ──
 *
 * This screen was `p-6 max-w-5xl mx-auto`, a centred column about half the
 * window, while every sibling admin screen uses `min-h-screen px-4 py-6
 * lg:px-8`. The queue rows are the widest in the app — amount, agent, masked
 * bank account and three decision buttons — so the narrow column was also
 * where they wrapped first.
 *
 * ── WHY IT WAS EMPTY, WHICH IS NOT A BUG ──
 *
 * There are two ways a commission stops being owed, and they compete for the
 * same rows:
 *
 *   1. an admin settles it directly on จ่ายเงิน ("จ่ายแล้ว" / "จ่ายทั้งหมด")
 *   2. the AGENT asks to withdraw it, and an admin approves here
 *
 * An agent can only draw on rows still marked รอจ่าย. So a company that pays
 * from the payout screen will see this queue empty forever — correct
 * behaviour that looks exactly like a broken page. The old empty state
 * ("เมื่อมีตัวแทนส่งคำขอเบิก รายการจะแสดงที่นี่") was true and did not say
 * that, which is how it produced a bug report instead of an answer.
 *
 * These tests pin the explanation, not its wording-in-general: each assertion
 * is on one fact an admin needs to stop investigating.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const post = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: vi.fn(),
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

import CommissionWithdrawalsView from '../CommissionWithdrawalsView.vue'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'

const TLI = { id: 4, name: 'Thai Life insurance', slug: 'tli' }

function request(over: Record<string, unknown> = {}) {
  return {
    id: 1,
    agent_id: 42,
    agent_name: 'สมชาย ใจดี',
    amount_satang: 250000,
    status: 'pending_review',
    status_label: 'รอตรวจสอบ',
    rejection_reason: null,
    decided_at: null,
    decided_by: null,
    transferred_at: null,
    transfer_reference: null,
    bank_name: 'กสิกรไทย',
    bank_account_number_masked: '****1234',
    bank_account_holder_name: 'สมชาย ใจดี',
    item_count: 3,
    created_at: '2026-09-14T00:00:00Z',
    ...over,
  }
}

async function mountQueue(rows: unknown[] = []) {
  get.mockImplementation(async (path: string) => {
    if (String(path).startsWith('/commission-withdrawal-settings')) return { min_withdrawal_satang: null }
    if (String(path).startsWith('/commission-withdrawals')) return { data: rows }
    if (String(path).startsWith('/companies')) return { data: [TLI] }

    return { data: [] }
  })

  const active = useActiveCompanyStore()
  active.companies = [TLI]
  active.selectedId = TLI.id

  const wrapper = mount(CommissionWithdrawalsView, {
    global: { stubs: { EmptyState: true, Icon: true, LoadingSkeleton: true, CompanyScopeNotice: true } },
  })
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  post.mockResolvedValue({ data: {} })
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
})

describe('the page fills the window', () => {
  it('uses the same shell as every other admin screen', async () => {
    const wrapper = await mountQueue()

    const main = wrapper.get('main').classes()
    expect(main).toContain('min-h-screen')
    expect(main).toContain('lg:px-8')
    // The specific thing that made it look unfinished next to จ่ายเงิน.
    expect(main).not.toContain('max-w-5xl')
  })

  it('carries header figures that name the tab they describe', async () => {
    /*
     * The page loads ONE status at a time (`?status=`), so the only totals it
     * holds are about the rows currently listed. A header reading "รอโอนรวม"
     * over a list filtered to something else would be a figure about money
     * nobody measured — the same F-10 defect the payout screen was fixed for.
     */
    const wrapper = await mountQueue([request(), request({ id: 2, amount_satang: 150000 })])

    const kpis = wrapper.findComponent(HeroHeader).props('kpis') as Array<{ label: string; value: unknown }>
    // Destructured rather than indexed: `noUncheckedIndexedAccess` makes
    // kpis[0] possibly-undefined, and `!` in a test is how a failure turns
    // into a crash with a worse message than the assertion would have given.
    const [count, total] = kpis

    expect(kpis).toHaveLength(2)
    expect(count?.label).toContain('รอตรวจสอบ')
    expect(count?.value).toBe(2)
    expect(total?.value).toBe('4,000.00 บาท')
  })
})

describe('an empty queue explains itself', () => {
  it('says the request can only come from the agent', async () => {
    // The first thing an admin looks for is the button to create one here.
    // There isn't one, and saying so is faster than letting them hunt.
    const wrapper = await mountQueue([])

    expect(wrapper.get('[data-test="withdrawal-empty-help"]').text()).toContain('พอร์ทัลสมาชิก')
  })

  it('names the reason the queue can be empty forever', async () => {
    /*
     * THE ONE THAT MATTERS. Settling rows on จ่ายเงิน takes them out of what
     * an agent is able to ask for — so the two screens are alternatives, not
     * steps. Without this sentence the correct outcome is indistinguishable
     * from a broken page, which is exactly what got reported.
     */
    const wrapper = await mountQueue([])

    const help = wrapper.get('[data-test="withdrawal-empty-help"]').text()
    expect(help).toContain('จ่ายทั้งหมด')
    expect(help).toContain('ไม่ใช่ระบบเสีย')
    expect(wrapper.find('[data-test="link-payouts"]').exists()).toBe(true)
  })

  it('points at the other tabs before anybody files a bug', async () => {
    const wrapper = await mountQueue([])

    expect(wrapper.get('[data-test="withdrawal-empty-help"]').text()).toContain('ทั้งหมด')
  })

  it('gets out of the way once there is a queue', async () => {
    // An explanation of emptiness printed under a list of real requests is
    // noise on the screen where an admin is moving money.
    const wrapper = await mountQueue([request()])

    expect(wrapper.find('[data-test="withdrawal-empty-help"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('สมชาย ใจดี')
  })
})
