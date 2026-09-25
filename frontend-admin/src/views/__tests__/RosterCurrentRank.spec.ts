/**
 * The roster's "ขั้นปัจจุบัน" (2026-09-25).
 *
 * Owner, planning UAT-017: no screen showed which rung an agent held, so an
 * admin checking the ranking job had to reverse-engineer it from the size of
 * commission rows — on a plan where the rung IS the pay (ADR-043).
 *
 * The server sends three states (see UserResource). The screen's job is to
 * keep them apart: the rung, "ยังไม่มีขั้น", and nothing at all for a plan
 * that has no ranks.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
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

function agent(id: number, name: string, over: Record<string, unknown> = {}) {
  return {
    id,
    name,
    first_name: name,
    last_name: null,
    email: `a${id}@example.test`,
    phone: null,
    role: 'agent',
    company: { id: 4, name: 'UAT' },
    has_passed_basic_cert: true,
    is_active: true,
    is_unconfirmed_applicant: false,
    agent_approval_status: 'approved',
    registered_via: 'email',
    is_team_leader: false,
    created_at: '2026-09-01T00:00:00Z',
    permissions: { update: true, deactivate: true, restore: true, move_company: true },
    removal_blockers: [],
    ...over,
  }
}

let mounted: ReturnType<typeof mount>[] = []

afterEach(() => {
  mounted.forEach((w) => w.unmount())
  mounted = []
})

async function mountRoster(rows: unknown[]) {
  get.mockImplementation(async (path: string) => {
    const p = String(path)
    if (p.startsWith('/users')) return { data: rows }

    return { data: [] }
  })

  const wrapper = mount(AgentRosterView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot name="actions" /><slot name="tabs" /><slot /></div>' },
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
        CompanyScopeNotice: true,
        AgentEditModal: true,
        SuccessDialog: true,
        ConfirmDialog: true,
        RouterLink: { props: ['to'], template: '<a><slot /></a>' },
      },
    },
  })
  await flushPromises()
  mounted.push(wrapper)

  return wrapper
}

function rankLine(wrapper: Awaited<ReturnType<typeof mountRoster>>, name: string) {
  const card = wrapper.findAll('div.bg-white\\/95').find((c) => c.text().includes(name))
  if (!card) throw new Error(`no roster card for ${name}`)

  return card.find('[data-test="agent-current-rank"]')
}

beforeEach(() => {
  get.mockReset()
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
  useActiveCompanyStore().setCompany(null)
})

describe('AgentRosterView — ขั้นปัจจุบัน', () => {
  it('names the rung, and says when it is the breakaway one', async () => {
    const wrapper = await mountRoster([
      agent(1, 'ผู้จัดการ ก', { current_rank: { id: 3, name: 'UAT ขั้นผู้จัดการ', is_breakaway: true } }),
    ])

    const line = rankLine(wrapper, 'ผู้จัดการ ก')
    expect(line.text()).toContain('UAT ขั้นผู้จัดการ')
    expect(line.text()).toContain('ขั้นตัดสาย')
  })

  it('says "ยังไม่มีขั้น" out loud rather than showing nothing', async () => {
    // Every agent starts here until the daily job first runs. A blank here
    // would be indistinguishable from a plan with no ranks at all.
    const wrapper = await mountRoster([agent(2, 'สมาชิกใหม่', { current_rank: null })])

    expect(rankLine(wrapper, 'สมาชิกใหม่').text()).toContain('ยังไม่มีขั้น')
  })

  it('shows no rank line at all on a plan that has no ranks', async () => {
    // The server omits the key for Unilevel, Binary, Matrix and Affiliate.
    const wrapper = await mountRoster([agent(3, 'สมาชิก Unilevel')])

    expect(rankLine(wrapper, 'สมาชิก Unilevel').exists()).toBe(false)
  })
})
