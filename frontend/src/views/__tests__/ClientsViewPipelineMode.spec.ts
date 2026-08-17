/**
 * ClientsView — the two view modes added by TASK-169 Phase 3.
 *
 * What breaks SILENTLY if these assertions are lost:
 *
 *  1. THE MODE STOPS LIVING IN THE URL. Phase 4 redirects `/pipeline` — a URL
 *     agents have bookmarked and HomeView still links to (§5.3, the human
 *     overruled removing it) — onto this screen. If the mode reverts to
 *     component state, that redirect has nowhere specific to land and every
 *     one of those agents is dropped on the client list instead of the board,
 *     with no error and no clue. Browser back/forward stops moving between
 *     the modes at the same moment, for the same reason.
 *
 *  2. THE BOARD LOADS WHEN NOBODY ASKED. `PipelineBoard` fetches
 *     GET /referrals on mount. Mounting it in list mode (v-show instead of
 *     v-if, say) makes the default screen pay for a request it never renders.
 *
 *  3. A MIXED CATALOGUE COLLAPSES TO ONE JOURNEY. The board's own spec
 *     covers this in isolation; asserted again HERE because the merge is the
 *     thing under test — a board that is correct standalone and wired up
 *     wrong is just as broken (ADR-026).
 *
 * The API is mocked at `@/api/client`; tenant isolation for /clients and
 * /referrals is enforced and tested server-side (BR-6, §5.4). Everything the
 * LIST mode does is asserted in ClientsView.spec.ts, which this file does not
 * duplicate.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'

const get = vi.fn()
const post = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
    downloadAbsolute: vi.fn(),
  },
  ApiError: class extends Error {},
}))

import ClientsView from '../ClientsView.vue'
import PipelineBoard from '@/design-system/components/PipelineBoard.vue'
import TabFilterBar from '@/design-system/components/TabFilterBar.vue'

// jsdom implements no scrolling; TabFilterBar centres the active tab with
// el.scrollTo() in its >3-tab layout, which the stage axis is.
Element.prototype.scrollTo = function () {} as Element['scrollTo']

const CLIENT_NAME = 'คุณสมชาย ใจดี'

const MEDICAL = [
  { key: 'complete_registered', label: 'Complete Registered' },
  { key: 'waiting_appointment', label: 'Waiting Appointment' },
  { key: 'finish_1st_doctor_meeting', label: 'Finish 1st Doctor Meeting' },
  { key: 'complete_payment', label: 'Complete Payment' },
  { key: 'ongoing_next_meeting', label: 'Ongoing Next Meeting' },
]
const DIRECT = [
  { key: 'complete_registered', label: 'Complete Registered' },
  { key: 'complete_payment', label: 'Complete Payment' },
]

function boardReferral(id: number, clientName: string, stages: typeof MEDICAL, at: number) {
  return {
    id,
    client: { id: id * 100, name: clientName, phone: '0800000000' },
    agent: null,
    product: { id: id * 10, name: `แพ็กเกจ ${id}`, price_satang: 890000 },
    branch: 'สาขาสีลม',
    preferred_time: null,
    current_stage: stages[at],
    meeting_number: null,
    pipeline: { stages, next_stage: stages[at + 1] ?? null },
    submitted_at: '2026-08-01T00:00:00Z',
  }
}

function clientFixture() {
  return {
    id: 1,
    name: CLIENT_NAME,
    phone: '0800000000',
    email: null,
    consent_given_at: null,
    health_notes: null,
    referring_agent_id: 9,
    status: { key: 'new', label: 'New' },
    lead_source: null,
    date_of_birth: null,
    address: null,
    province: null,
    occupation: null,
    referrals: [],
    created_at: '2026-08-01T00:00:00Z',
    client_category_id: null,
    client_category_name: null,
  }
}

function wire(boardReferrals: unknown[] = []) {
  get.mockImplementation((path: string) => {
    if (path === '/clients' || path.startsWith('/clients?'))
      return Promise.resolve({ data: [clientFixture()] })
    if (path === '/products') return Promise.resolve({ data: [] })
    if (path === '/user-certifications')
      return Promise.resolve({ data: [{ id: 1, cert_tier: { id: 1, key: 'basic', name: 'Basic' } }] })
    if (path === '/client-categories') return Promise.resolve({ data: [] })
    // TASK-174 — the view reads the co-agent-split switch once per page load
    // alongside the other company-wide options. Answered OFF here because
    // nothing in this file is about the split, and OFF is the default state a
    // company starts in; leaving it unmocked would make the board's own
    // assertions depend on a swallowed rejection.
    if (path === '/commission-split-settings')
      return Promise.resolve({ data: { is_enabled: false } })
    if (path === '/referrals') return Promise.resolve({ data: boardReferrals })
    if (path.endsWith('/stage-logs')) return Promise.resolve({ data: [] })
    if (path.endsWith('/documents')) return Promise.resolve({ data: [] })
    if (path.endsWith('/activities')) return Promise.resolve({ data: [] })
    if (path.startsWith('/orders'))
      return Promise.resolve({ data: [], meta: { current_page: 1, last_page: 1 } })
    throw new Error(`unexpected GET ${path}`)
  })
}

/**
 * HeroHeader is replaced by a stub that RENDERS ITS SLOTS — the mode switch
 * lives in the `#tabs` slot, and a bare `true` stub would silently drop the
 * control under test.
 */
const stubs = {
  HeroHeader: { template: '<div><slot name="actions" /><slot name="tabs" /></div>' },
  LoadingSkeleton: true,
  FilterSheet: true,
  BuddhistDateInput: true,
  AuthenticatedMedia: true,
  ConfirmDialog: true,
}

async function mountAt(path: string, boardReferrals: unknown[] = []) {
  wire(boardReferrals)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/clients', component: { template: '<div />' } }],
  })
  await router.push(path)
  await router.isReady()
  const wrapper = mount(ClientsView, { global: { stubs, plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

function modeTab(wrapper: ReturnType<typeof mount>, label: string) {
  const tab = wrapper
    .findAllComponents(TabFilterBar)[0]!
    .findAll('button')
    .find((b) => b.text().includes(label))
  if (!tab) throw new Error(`no view-mode tab "${label}"`)
  return tab
}

describe('ClientsView — view mode is read from the URL', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('defaults to the client list, and does NOT mount (or fetch for) the board', async () => {
    const { wrapper } = await mountAt('/clients')

    expect(wrapper.text()).toContain(CLIENT_NAME)
    expect(wrapper.findComponent(PipelineBoard).exists()).toBe(false)
    // The board's own fetch. list mode must not pay for it.
    expect(get).not.toHaveBeenCalledWith('/referrals')
  })

  it('lands on the BOARD when the URL says so — this is what /pipeline redirects to', async () => {
    const { wrapper } = await mountAt('/clients?view=pipeline', [
      boardReferral(1, 'ลูกค้าบอร์ด', DIRECT, 0),
    ])

    expect(wrapper.findComponent(PipelineBoard).exists()).toBe(true)
    expect(wrapper.text()).toContain('ลูกค้าบอร์ด')
    // The client roster is not underneath it.
    expect(wrapper.text()).not.toContain(CLIENT_NAME)
    expect(get).toHaveBeenCalledWith('/referrals')
  })

  it('keeps client search + the category filter with the LIST, not the board', async () => {
    // Phase 3 gated these on the mode; the board has its own stage filter and
    // a client-name search over it would filter nothing. Asserted in both
    // directions because a control stuck hidden is as broken as one stuck on.
    const { wrapper, router } = await mountAt('/clients')
    expect(wrapper.find('input[placeholder="ค้นหาชื่อ, เบอร์โทร, อีเมล..."]').exists()).toBe(true)

    await router.push('/clients?view=pipeline')
    await flushPromises()
    expect(wrapper.find('input[placeholder="ค้นหาชื่อ, เบอร์โทร, อีเมล..."]').exists()).toBe(false)

    await router.push('/clients')
    await flushPromises()
    expect(wrapper.find('input[placeholder="ค้นหาชื่อ, เบอร์โทร, อีเมล..."]').exists()).toBe(true)
  })

  it('an unrecognised ?view= degrades to the list rather than rendering nothing', async () => {
    const { wrapper } = await mountAt('/clients?view=nonsense')

    expect(wrapper.findComponent(PipelineBoard).exists()).toBe(false)
    expect(wrapper.text()).toContain(CLIENT_NAME)
  })

  it('switching mode WRITES the URL — the tab is not local state', async () => {
    const { wrapper, router } = await mountAt('/clients', [boardReferral(1, 'ลูกค้าบอร์ด', DIRECT, 0)])

    await modeTab(wrapper, 'กระบวนการขาย').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.query.view).toBe('pipeline')
    expect(wrapper.findComponent(PipelineBoard).exists()).toBe(true)
  })

  it('switching back DROPS the param, keeping /clients canonical for the default', async () => {
    const { wrapper, router } = await mountAt('/clients?view=pipeline', [])

    await modeTab(wrapper, 'รายชื่อลูกค้า').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.query.view).toBeUndefined()
    expect(router.currentRoute.value.fullPath).toBe('/clients')
    expect(wrapper.findComponent(PipelineBoard).exists()).toBe(false)
  })

  it('browser BACK returns to the mode the agent came from, not off the screen', async () => {
    const { wrapper, router } = await mountAt('/clients', [boardReferral(1, 'ลูกค้าบอร์ด', DIRECT, 0)])

    await modeTab(wrapper, 'กระบวนการขาย').trigger('click')
    await flushPromises()
    expect(wrapper.findComponent(PipelineBoard).exists()).toBe(true)

    // `push` (not `replace`) is what makes this entry exist at all.
    router.back()
    await flushPromises()

    expect(router.currentRoute.value.query.view).toBeUndefined()
    expect(wrapper.findComponent(PipelineBoard).exists()).toBe(false)
    expect(wrapper.text()).toContain(CLIENT_NAME)

    // …and forward again, so the pair is symmetric.
    router.forward()
    await flushPromises()
    expect(wrapper.findComponent(PipelineBoard).exists()).toBe(true)
  })
})

describe('ClientsView — the merged board is still ADR-026 correct', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('renders a two-stage direct sale and a five-stage medical deal in their own journeys', async () => {
    const { wrapper } = await mountAt('/clients?view=pipeline', [
      boardReferral(1, 'ลูกค้าขายตรง', DIRECT, 0),
      boardReferral(2, 'ลูกค้าแพ็กเกจแพทย์', MEDICAL, 1),
    ])

    expect(wrapper.text()).toContain('ลูกค้าขายตรง')
    expect(wrapper.text()).toContain('ลูกค้าแพ็กเกจแพทย์')

    const journeyHeadings = wrapper.findAll('p').filter((p) => p.text() === 'เส้นทางการขาย')
    expect(journeyHeadings).toHaveLength(2)

    // Each row offers only the move its OWN template allows next. If the
    // merge ever flattens to one column set, the direct sale's next stage
    // stops being ชำระเงินสำเร็จ.
    expect(wrapper.text()).toContain('ไป: ชำระเงินสำเร็จ')
    expect(wrapper.text()).toContain('ไป: พบแพทย์ครั้งแรกแล้ว')
  })

  it('shows the board empty state, not a bare grid, for an agent with no deals', async () => {
    const { wrapper } = await mountAt('/clients?view=pipeline', [])

    expect(wrapper.findComponent(PipelineBoard).exists()).toBe(true)
    expect(wrapper.text()).toContain('ยังไม่มีดีลในกระบวนการขาย')
  })
})
