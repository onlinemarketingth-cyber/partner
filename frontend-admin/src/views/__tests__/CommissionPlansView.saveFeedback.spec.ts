/**
 * ADR-052 — "saved" is said once, by one dialog, and only when it is true.
 *
 * Owner, 2026-10-01: every save must show a dialog saying it saved, and the
 * screen must already show what was stored — no F5.
 *
 * THE BUG THAT STARTED THIS lives in the first block. The step rail beside
 * step 2 read the rank-settings FORM, so typing 90 days / ยอดทั้งทีม / รายวัน
 * turned it green while nothing had been saved, and a UAT run was blocked a
 * day later by "company has no rank settings". The rail is a claim about what
 * the company HAS, so every answer it gives is pinned here to the SAVED value:
 * untouched by typing, moved only by the server's answer, and left alone by a
 * save that failed.
 *
 * The rest pins the shape every write on this screen now has:
 *   - the dialog appears after the server answered, never before, never on a
 *     failure;
 *   - what is on screen afterwards is the SERVER's value (every mock below
 *     answers with something different from what was typed, so a screen that
 *     kept the typed value fails);
 *   - every delete asks first.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'

const get = vi.fn()
const put = vi.fn()
const post = vi.fn()
const del = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: (...args: unknown[]) => put(...args),
    patch: vi.fn(),
    delete: (...args: unknown[]) => del(...args),
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

import CommissionPlansView from '../CommissionPlansView.vue'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import { saveFeedbackState } from '@/composables/useSaveFeedback'

const CO = { id: 2, name: 'AIA', slug: 'aia' }
const READY = { state: 'ready', blocking_step: null, products_total: 1, products_covered: 1, issues: [], can_fix: true }

interface Rank {
  id: number
  company_id: number
  name: string
  volume_threshold: number
  sort_order: number
  rate_type: string
  rate_value: number
  is_breakaway_rank: boolean
}

function rank(id: number, name: string): Rank {
  return { id, company_id: CO.id, name, volume_threshold: 0, sort_order: id, rate_type: 'percentage', rate_value: 500, is_breakaway_rank: false }
}

function leaderRate(id: number, level: number | null, rateValue: number) {
  return {
    id,
    company_id: CO.id,
    product: null,
    product_category: null,
    manager_cert_tier: null,
    level,
    rate_type: 'percentage',
    rate_value: rateValue,
    override_mode: null,
    effective_from: '2020-01-01T00:00:00.000000Z',
    effective_to: null,
  }
}

/** The fake server's state. Tests mutate it to play "the write landed". */
interface ServerState {
  planType: string
  basis: string
  rankSettings: Record<string, unknown> | null
  ranks: Rank[]
  leaderRates: ReturnType<typeof leaderRate>[]
  maxOverrideDepth: number | null
  minWithdrawalSatang: number | null
  whtRate: number | null
  pvSatang: number | null
}

let server: ServerState

function mockApi(over: Partial<ServerState> = {}) {
  server = {
    planType: 'stairstep_breakaway',
    basis: 'price',
    rankSettings: null,
    ranks: [rank(1, 'ขั้นเริ่มต้น')],
    leaderRates: [],
    maxOverrideDepth: null,
    minWithdrawalSatang: null,
    whtRate: null,
    pvSatang: null,
    ...over,
  }

  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/commission-readiness')) return READY
    if (path.startsWith('/commission-settings')) {
      return {
        data: {
          commission_plan_type: server.planType,
          commission_basis: server.basis,
          commission_override_mode: 'additive',
          deepest_manager_chain: 3,
          max_override_depth: server.maxOverrideDepth,
          override_compression: false,
        },
      }
    }
    if (path.startsWith('/commission-withdrawal-settings')) {
      return { min_withdrawal_satang: server.minWithdrawalSatang, wht_rate: server.whtRate }
    }
    if (path.startsWith('/companies')) return { data: [CO] }
    if (path.startsWith('/products')) {
      return {
        data: [{
          id: 7, company_id: CO.id, name: 'แพ็กเกจสุขภาพ', category: null,
          price_satang: 1_000_000, effective_price_satang: 1_000_000, pv_satang: server.pvSatang,
          commission_plan_type: null, effective_plan_type: server.planType,
          commission_rate_type: null, is_sellable_here: true,
          permissions: { update: true, delete: true, set_commission_rule: true },
        }],
      }
    }
    // '' is what the API sends for a company with no settings row yet.
    if (path.startsWith('/agent-rank-settings')) return server.rankSettings ? { data: server.rankSettings } : ''
    if (path.startsWith('/agent-ranks')) return { data: server.ranks }
    if (path.startsWith('/commission-override-rules')) return { data: server.leaderRates }
    if (path.startsWith('/commission-rules')) {
      return {
        data: [{
          id: 11, company_id: CO.id, cert_tier: null, product: null, product_category: null,
          rate_type: 'percentage', rate_value: 500,
          effective_from: '2020-01-01T00:00:00.000000Z', effective_to: null,
          renewal_rate_type: null, renewal_rate_value: null, renewal_recurs: false,
        }],
      }
    }

    return { data: [] }
  })

  const active = useActiveCompanyStore()
  active.companies = [CO]
  active.selectedId = CO.id
}

let wrappers: VueWrapper[] = []

async function mountView(): Promise<VueWrapper> {
  const wrapper = mount(CommissionPlansView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot name="tabs" /></div>' },
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
        BuddhistDateInput: true,
        CalendarDatePicker: true,
        CommissionSplitSettingCard: true,
        PlanShapePreview: true,
        RateResolutionMatrix: true,
        RateImpactPreview: true,
        InfoPopover: true,
      },
    },
  })
  wrappers.push(wrapper)
  await flushPromises()

  return wrapper
}

async function goToStep(wrapper: VueWrapper, step: 2 | 3 | 4) {
  await wrapper.get(`[data-test="step-tab-${step}"]`).trigger('click')
  await flushPromises()
}

/** One rail row: its answer line and its status dot's label. */
function railRow(wrapper: VueWrapper, cardId: string): { text: string; status: string | undefined } {
  const row = wrapper.get(`[data-test="step-checklist-${cardId}"]`)

  return { text: row.text(), status: row.find('[aria-label]').attributes('aria-label') }
}

async function pressConfirm(wrapper: VueWrapper) {
  const buttons = wrapper.get('[data-test="delete-confirm"]').findAll('button')
  await buttons[buttons.length - 1]!.trigger('click')
  await flushPromises()
}

async function pressCancel(wrapper: VueWrapper) {
  await wrapper.get('[data-test="delete-confirm"]').findAll('button')[0]!.trigger('click')
  await flushPromises()
}

const readinessReads = () => get.mock.calls.filter((c) => String(c[0]).startsWith('/commission-readiness')).length

beforeEach(() => {
  get.mockReset()
  put.mockReset(); put.mockResolvedValue({ data: {} })
  post.mockReset(); post.mockResolvedValue({ data: {} })
  del.mockReset(); del.mockResolvedValue({})
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never

  Element.prototype.scrollIntoView = vi.fn()
  Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: vi.fn().mockReturnValue({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }),
  })
})

afterEach(() => {
  wrappers.forEach((w) => w.unmount())
  wrappers = []
})

// ── The bug: the rail went green from unsaved input ─────────────────────────

describe('the step rail reads SAVED rank settings, never the form', () => {
  async function typeRankSettings(wrapper: VueWrapper) {
    await wrapper.get('[data-test="rank-trailing-window"]').setValue('90')
    await wrapper.get('[data-test="rank-volume-scope"]').setValue('group')
    await wrapper.get('[data-test="rank-recalc-frequency"]').setValue('daily')
  }

  it('stays ยังไม่ได้ตั้ง while the admin types and has not saved', async () => {
    mockApi({ rankSettings: null })
    const wrapper = await mountView()
    await goToStep(wrapper, 2)

    await typeRankSettings(wrapper)
    await flushPromises()

    const scope = railRow(wrapper, 'rank-volume-scope')
    const cadence = railRow(wrapper, 'rank-cadence')
    expect(scope.text).toContain('ยังไม่ได้ตั้ง')
    expect(scope.status).toBe('ยังไม่ตั้ง')
    expect(cadence.text).toContain('ยังไม่ได้ตั้ง')
    expect(cadence.status).toBe('ยังไม่ตั้ง')
    expect(put).not.toHaveBeenCalled()
    expect(saveFeedbackState.show).toBe(false)
  })

  it('shows what the SERVER stored once the save answers, and only then says so', async () => {
    mockApi({ rankSettings: null })
    let answer!: (value: unknown) => void
    put.mockImplementationOnce(() => new Promise((resolve) => { answer = resolve }))

    const wrapper = await mountView()
    await goToStep(wrapper, 2)
    await typeRankSettings(wrapper)
    await wrapper.get('[data-test="rank-settings-form"]').trigger('submit')
    await flushPromises()

    // In flight: nothing has been stored yet, so nothing may say it has.
    expect(saveFeedbackState.show).toBe(false)
    expect(railRow(wrapper, 'rank-cadence').status).toBe('ยังไม่ตั้ง')

    // The server keeps the scope but stores its own window and cadence.
    answer({ data: { trailing_window_days: 120, volume_scope: 'group', recalculation_frequency: 'weekly' } })
    await flushPromises()

    expect(railRow(wrapper, 'rank-volume-scope').text).toContain('ยอดทั้งทีม · ย้อนหลัง 120 วัน')
    expect(railRow(wrapper, 'rank-volume-scope').status).toBe('ตั้งแล้ว')
    expect(railRow(wrapper, 'rank-cadence').text).toContain('รายสัปดาห์')
    // The form follows the stored row too — the typed 90 is gone.
    expect((wrapper.get('[data-test="rank-trailing-window"]').element as HTMLInputElement).value).toBe('120')
    expect((wrapper.get('[data-test="rank-recalc-frequency"]').element as HTMLSelectElement).value).toBe('weekly')
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('บันทึกการตั้งค่าการเลื่อนขั้นแล้ว — ย้อนหลัง 120 วัน · ยอดทั้งทีม · คำนวณใหม่รายสัปดาห์')
  })

  it('stays ยังไม่ได้ตั้ง after a save the server refused, says why, and raises no dialog', async () => {
    mockApi({ rankSettings: null })
    put.mockRejectedValueOnce(new Error('422'))

    const wrapper = await mountView()
    await goToStep(wrapper, 2)
    await typeRankSettings(wrapper)
    await wrapper.get('[data-test="rank-settings-form"]').trigger('submit')
    await flushPromises()

    expect(put).toHaveBeenCalledTimes(1)
    expect(wrapper.get('[data-test="rank-error"]').text()).toContain('บันทึกไม่สำเร็จ')
    expect(railRow(wrapper, 'rank-volume-scope').text).toContain('ยังไม่ได้ตั้ง')
    expect(railRow(wrapper, 'rank-cadence').status).toBe('ยังไม่ตั้ง')
    expect(saveFeedbackState.show).toBe(false)
  })
})

// ── Step 2's own status, without F5 ─────────────────────────────────────────

describe('step 2 status follows a structural save at once', () => {
  it('turns เสร็จแล้ว when the first rank lands, with the server-given name in the dialog', async () => {
    mockApi({ ranks: [] })
    post.mockImplementation(async (path: string) => {
      if (path === '/agent-ranks') {
        server.ranks = [rank(9, 'ขั้นทอง')]

        return { data: server.ranks[0] }
      }

      return { data: {} }
    })

    const wrapper = await mountView()
    await goToStep(wrapper, 2)
    expect(wrapper.get('[data-test="step-pill-2"]').text()).toBe('ยังไม่ครบ')

    await wrapper.get('[data-test="add-rank"]').trigger('click')
    await wrapper.get('[data-test="rank-form"]').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[data-test="step-pill-2"]').text()).toBe('เสร็จแล้ว')
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('เพิ่มอันดับ "ขั้นทอง" แล้ว — 5.00%')
  })
})

// ── Deletes ask first ───────────────────────────────────────────────────────

describe('every delete asks first', () => {
  it('asks before deleting a rank, then deletes it and says so', async () => {
    mockApi({ ranks: [rank(1, 'ขั้นเริ่มต้น'), rank(2, 'ขั้นทอง')] })
    const wrapper = await mountView()
    await goToStep(wrapper, 2)

    await wrapper.get('[data-test="delete-rank-2"]').trigger('click')
    await flushPromises()

    expect(del).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="delete-confirm"]').text()).toContain('ขั้นทอง')

    await pressConfirm(wrapper)

    expect(del).toHaveBeenCalledWith('/agent-ranks/2', undefined)
    expect(wrapper.find('[data-test="delete-rank-2"]').exists()).toBe(false)
    // The dialog closed (its wrapper stays mounted; its contents do not).
    expect(wrapper.get('[data-test="delete-confirm"]').findAll('button')).toHaveLength(0)
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('ลบอันดับ "ขั้นทอง" แล้ว')
  })

  it('deletes nothing and says nothing when the admin cancels', async () => {
    mockApi({ ranks: [rank(1, 'ขั้นเริ่มต้น'), rank(2, 'ขั้นทอง')] })
    const wrapper = await mountView()
    await goToStep(wrapper, 2)

    await wrapper.get('[data-test="delete-rank-2"]').trigger('click')
    await pressCancel(wrapper)

    expect(del).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="delete-rank-2"]').exists()).toBe(true)
    expect(saveFeedbackState.show).toBe(false)
  })

  it('keeps the row and raises no dialog when the server refuses the delete', async () => {
    mockApi({ ranks: [rank(1, 'ขั้นเริ่มต้น'), rank(2, 'ขั้นทอง')] })
    del.mockRejectedValueOnce(new Error('409'))
    const wrapper = await mountView()
    await goToStep(wrapper, 2)

    await wrapper.get('[data-test="delete-rank-2"]').trigger('click')
    await pressConfirm(wrapper)

    expect(wrapper.find('[data-test="delete-rank-2"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="rank-error"]').text()).toContain('ลบไม่สำเร็จ')
    expect(saveFeedbackState.show).toBe(false)
  })

  it('asks for a team-leader rate in the screen\'s own dialog, not the browser\'s', async () => {
    const nativeConfirm = vi.spyOn(window, 'confirm')
    mockApi({ planType: 'unilevel', leaderRates: [leaderRate(21, null, 200)] })
    const wrapper = await mountView()
    await goToStep(wrapper, 4)

    await wrapper.get('[data-test="delete-leader-rule-21"]').trigger('click')
    await flushPromises()

    expect(nativeConfirm).not.toHaveBeenCalled()
    expect(del).not.toHaveBeenCalled()

    await pressConfirm(wrapper)

    expect(del).toHaveBeenCalledWith('/commission-override-rules/21', undefined)
    expect(saveFeedbackState.body).toContain('ลบอัตราหัวหน้าทีม')
  })
})

// ── Step 4's answers come from the server, not from the inputs ──────────────

describe('step 4 — the rail and the fields show what is stored', () => {
  it('leaves the floor ไม่กำหนด while typed, then shows the stored figure and refreshes the banner', async () => {
    mockApi({ planType: 'unilevel', minWithdrawalSatang: null })
    // Typed 500, stored 500.50 — the screen must show the second.
    put.mockResolvedValue({ company_id: CO.id, min_withdrawal_satang: 50050, wht_rate: null })

    const wrapper = await mountView()
    await goToStep(wrapper, 4)
    await wrapper.get('[data-test="withdrawal-min-input"]').setValue('500')
    await flushPromises()

    expect(railRow(wrapper, 'withdrawal-min').text).toContain('ไม่กำหนด')

    const readsBefore = readinessReads()
    await wrapper.get('[data-test="withdrawal-min-save"]').trigger('click')
    await flushPromises()

    expect((wrapper.get('[data-test="withdrawal-min-input"]').element as HTMLInputElement).value).toBe('500.50')
    expect(railRow(wrapper, 'withdrawal-min').text).toContain('฿500.5')
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('บันทึกยอดขั้นต่ำในการเบิกแล้ว — 500.5 บาท')
    // Through commissionApi now, so the readiness banner re-reads (it used
    // plain `api` and was the one write the banner never heard about).
    expect(readinessReads()).toBeGreaterThan(readsBefore)
  })

  it('takes the tax from the answer and goes through commissionApi too', async () => {
    mockApi({ planType: 'unilevel', minWithdrawalSatang: 100000, whtRate: null })
    put.mockResolvedValue({ company_id: CO.id, min_withdrawal_satang: 100000, wht_rate: 150 })

    const wrapper = await mountView()
    await goToStep(wrapper, 4)
    await wrapper.get('[data-test="wht-input"]').setValue('1.499')
    expect(railRow(wrapper, 'withholding-tax').text).toContain('ไม่หัก')

    const readsBefore = readinessReads()
    await wrapper.get('[data-test="wht-save"]').trigger('click')
    await flushPromises()

    expect((wrapper.get('[data-test="wht-input"]').element as HTMLInputElement).value).toBe('1.5')
    expect(railRow(wrapper, 'withholding-tax').text).toContain('1.5%')
    expect(saveFeedbackState.body).toBe('บันทึกภาษีหัก ณ ที่จ่ายแล้ว — หัก 1.5%')
    expect(readinessReads()).toBeGreaterThan(readsBefore)
  })

  it('leaves the depth cap ไม่จำกัด while typed and after a refused save', async () => {
    mockApi({ planType: 'unilevel', maxOverrideDepth: null })
    put.mockRejectedValueOnce(new Error('422'))

    const wrapper = await mountView()
    await goToStep(wrapper, 4)
    await wrapper.get('[data-test="override-depth-input"]').setValue('2')
    await flushPromises()
    expect(railRow(wrapper, 'leader-depth-cap').text).toContain('ไม่จำกัด')

    await wrapper.get('[data-test="override-depth-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="override-depth-message"]').text()).toContain('บันทึกไม่สำเร็จ')
    expect(railRow(wrapper, 'leader-depth-cap').text).toContain('ไม่จำกัด')
    expect(saveFeedbackState.show).toBe(false)
  })

  it('puts a refused compression switch back where the server has it', async () => {
    mockApi({ planType: 'unilevel' })
    put.mockRejectedValueOnce(new Error('422'))

    const wrapper = await mountView()
    await goToStep(wrapper, 4)
    const toggle = wrapper.get('[data-test="override-compression-toggle"]')
    ;(toggle.element as HTMLInputElement).checked = true
    await toggle.trigger('change')
    await flushPromises()

    expect((toggle.element as HTMLInputElement).checked).toBe(false)
    expect(saveFeedbackState.show).toBe(false)
  })
})

// ── Step 2's ladder: the rail reads rows, the chart reads the draft ─────────

describe('the Unilevel ladder', () => {
  it('keeps the rail on the saved rates while a rung is being retyped', async () => {
    mockApi({ planType: 'unilevel', leaderRates: [leaderRate(4, 1, 500)] })
    const wrapper = await mountView()
    await goToStep(wrapper, 2)

    await wrapper.get('[data-test="ladder-rate-1"]').setValue('7')
    await flushPromises()

    expect(railRow(wrapper, 'leader-level-rates').text).toContain('1 ชั้น · 5%')
  })

  it('asks before a save that removes a stored rung, then deletes it and says so', async () => {
    mockApi({ planType: 'unilevel', leaderRates: [leaderRate(4, 1, 500), leaderRate(5, 2, 300)] })
    del.mockImplementation(async (path: string) => {
      if (path === '/commission-override-rules/5') server.leaderRates = server.leaderRates.filter((r) => r.id !== 5)

      return {}
    })

    const wrapper = await mountView()
    await goToStep(wrapper, 2)
    await wrapper.get('[data-test="ladder-remove"]').trigger('click')
    await wrapper.get('[data-test="ladder-save"]').trigger('click')
    await flushPromises()

    // Nothing sent yet — the dialog names the level that is about to go.
    expect(del).not.toHaveBeenCalled()
    expect(put).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="delete-confirm"]').text()).toContain('ชั้นที่ 2 (3.00%)')

    await pressConfirm(wrapper)

    expect(del).toHaveBeenCalledWith('/commission-override-rules/5', undefined)
    expect(railRow(wrapper, 'leader-level-rates').text).toContain('1 ชั้น · 5%')
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('บันทึกอัตราหัวหน้าทีมตามชั้นแล้ว — จ่าย 1 ชั้น (5.00%)')
  })

  it('sends nothing when the admin cancels a save that would remove a rung', async () => {
    mockApi({ planType: 'unilevel', leaderRates: [leaderRate(4, 1, 500), leaderRate(5, 2, 300)] })

    const wrapper = await mountView()
    await goToStep(wrapper, 2)
    await wrapper.get('[data-test="ladder-remove"]').trigger('click')
    await wrapper.get('[data-test="ladder-save"]').trigger('click')
    await flushPromises()
    await pressCancel(wrapper)

    expect(del).not.toHaveBeenCalled()
    expect(put).not.toHaveBeenCalled()
    expect(post).not.toHaveBeenCalled()
    expect(saveFeedbackState.show).toBe(false)
    // The draft is untouched — still one rung, still unsaved.
    expect(wrapper.find('[data-test="ladder-rate-2"]').exists()).toBe(false)
    expect((wrapper.get('[data-test="ladder-save"]').element as HTMLButtonElement).disabled).toBe(false)
  })

  it('re-reads the ladder when only part of it was saved, and says so without a dialog', async () => {
    mockApi({ planType: 'unilevel', leaderRates: [leaderRate(4, 1, 500)] })
    // Rung 1's update lands; rung 2's create is refused.
    put.mockImplementation(async (path: string, body: { rate_value: number }) => {
      if (path === '/commission-override-rules/4') server.leaderRates = [leaderRate(4, 1, body.rate_value)]

      return { data: {} }
    })
    post.mockRejectedValueOnce(new Error('422'))

    const wrapper = await mountView()
    await goToStep(wrapper, 2)
    await wrapper.get('[data-test="ladder-rate-1"]').setValue('6')
    await wrapper.get('[data-test="ladder-add"]').trigger('click')
    await wrapper.get('[data-test="ladder-rate-2"]').setValue('3')
    await wrapper.get('[data-test="ladder-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="ladder-message"]').text()).toContain('บันทึกไปได้บางชั้นแล้ว')
    // The editor now shows the server's ladder: rung 1 at its new 6%, and no
    // rung 2, because none was stored.
    expect((wrapper.get('[data-test="ladder-rate-1"]').element as HTMLInputElement).value).toBe('6')
    expect(wrapper.find('[data-test="ladder-rate-2"]').exists()).toBe(false)
    expect(railRow(wrapper, 'leader-level-rates').text).toContain('1 ชั้น · 6%')
    expect(saveFeedbackState.show).toBe(false)
  })
})

// ── Responses, not requests ─────────────────────────────────────────────────

describe('the screen takes the answer, not the request', () => {
  it('shows the basis the server answered with', async () => {
    mockApi({ planType: 'unilevel', basis: 'price' })
    // Asked for PV; the server says it is still ราคาขาย (e.g. refused quietly
    // by a rule this screen does not know about). The screen must agree with
    // the server, not with the click.
    put.mockResolvedValue({ data: { commission_basis: 'price' } })

    const wrapper = await mountView()
    await goToStep(wrapper, 2)
    await wrapper.get('[data-test="basis-option-pv"]').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/commission-settings', { commission_basis: 'pv', company_id: CO.id })
    expect(wrapper.get('[data-test="basis-option-price"]').classes().join(' ')).toContain('border-brand-600')
    expect(saveFeedbackState.body).toBe('เปลี่ยนฐานการคำนวณเป็น ราคาขาย แล้ว')
  })

  it('patches a product\'s PV with the stored figure', async () => {
    mockApi({ planType: 'unilevel', basis: 'pv', pvSatang: null })
    put.mockResolvedValue({ data: { id: 7, name: 'แพ็กเกจสุขภาพ', pv_satang: 99_900 } })

    const wrapper = await mountView()
    await goToStep(wrapper, 2)
    await wrapper.get('[data-test="pv-input-7"]').setValue('1000')
    await wrapper.get('[data-test="pv-save-7"]').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/products/7', { pv_satang: 100_000 })
    expect((wrapper.get('[data-test="pv-input-7"]').element as HTMLInputElement).value).toBe('999')
    expect(saveFeedbackState.body).toBe('บันทึก PV ของ "แพ็กเกจสุขภาพ" แล้ว — 999')
  })

  it('quotes the stored rate when a seller rate is saved, and raises nothing when it is refused', async () => {
    mockApi({ planType: 'unilevel' })
    post.mockResolvedValueOnce({ data: { id: 30, rate_type: 'percentage', rate_value: 750 } })

    const wrapper = await mountView()
    await goToStep(wrapper, 3)
    await wrapper.get('[data-test="add-category-rate"]').trigger('click')
    await wrapper.get('[data-test="rule-form-rate-value"]').setValue('7')
    await wrapper.get('[data-test="rule-form"]').trigger('submit')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('เพิ่มอัตราค่าคอมแล้ว — 7.50%')

    post.mockRejectedValueOnce(new Error('422'))
    const before = saveFeedbackState.count
    await wrapper.get('[data-test="add-category-rate"]').trigger('click')
    await wrapper.get('[data-test="rule-form-rate-value"]').setValue('9')
    await wrapper.get('[data-test="rule-form"]').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[data-test="rule-form-error"]').text()).toContain('บันทึกไม่สำเร็จ')
    expect(saveFeedbackState.count).toBe(before)
  })
})
