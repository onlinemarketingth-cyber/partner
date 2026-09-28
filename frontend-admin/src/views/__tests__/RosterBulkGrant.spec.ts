/**
 * 2026-09-28 — "อนุมัติการเรียน" on รายชื่อสมาชิก, one agent or many.
 *
 * Owner: "เพิ่มปุ่มอนุมัติ class เรียน และสามารถเลือกอนุมัติทั้งหมด หรือเลือก
 * อนุมัติหลายรายการได้". Reviewed as a mockup first; these pin what he
 * approved: ticking rows turns the list header into the action bar, only
 * agents who can take the grant can be ticked, the dialog says who is
 * granted and who already holds the tier, and one request goes to the bulk
 * endpoint.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const post = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
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

function person(id: number, name: string, over: Record<string, unknown> = {}) {
  return {
    id,
    name,
    first_name: name,
    last_name: null,
    email: `a${id}@example.test`,
    phone: null,
    role: 'agent',
    company: { id: 4, name: 'UAT' },
    has_passed_basic_cert: false,
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

const ROWS = [
  person(1, 'kreangyot', { role: 'company_admin', has_passed_basic_cert: null }),
  person(2, 'D UAT', { has_passed_basic_cert: true }),
  person(3, 'M UAT'),
  person(4, 'L UAT'),
  person(5, 'ผู้สมัครใหม่', { agent_approval_status: 'pending' }),
]

const TIERS = [
  { id: 10, key: 'basic', name: 'Basic' },
  { id: 11, key: 'intermediate', name: 'Intermediate' },
]

let mounted: ReturnType<typeof mount>[] = []

afterEach(() => {
  mounted.forEach((w) => w.unmount())
  mounted = []
})

async function mountRoster() {
  get.mockImplementation(async (path: string) => {
    const p = String(path)
    if (p.startsWith('/users')) return { data: ROWS }
    if (p.startsWith('/cert-tiers')) return { data: TIERS }
    if (p.startsWith('/user-certifications')) {
      return { data: [{ id: 99, user_id: 2, cert_tier: TIERS[0] }], meta: { last_page: 1 } }
    }

    return { data: [] }
  })

  const wrapper = mount(AgentRosterView, {
    attachTo: document.body,
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

function checkboxFor(wrapper: Awaited<ReturnType<typeof mountRoster>>, name: string) {
  return wrapper.find(`[aria-label="เลือก ${name}"]`)
}

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'company_admin' } as never
})

describe('selecting agents', () => {
  it('lets only approved, active agents be ticked', async () => {
    const wrapper = await mountRoster()

    expect(checkboxFor(wrapper, 'M UAT').attributes('disabled')).toBeUndefined()
    expect(checkboxFor(wrapper, 'kreangyot').attributes('disabled')).toBeDefined()
    expect(checkboxFor(wrapper, 'ผู้สมัครใหม่').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-test="select-all"]').element.parentElement?.textContent).toContain('3 ตัวแทน')
  })

  it('turns the header into the action bar once something is ticked', async () => {
    const wrapper = await mountRoster()
    expect(wrapper.find('[data-test="bulk-grant"]').exists()).toBe(false)

    await checkboxFor(wrapper, 'M UAT').setValue(true)
    await checkboxFor(wrapper, 'L UAT').setValue(true)

    expect(wrapper.find('[data-test="selected-count"]').text()).toContain('เลือกแล้ว 2 คน')
    expect(wrapper.find('[data-test="bulk-grant"]').text()).toContain('อนุมัติการเรียน 2 คน')
  })

  it('selects every tickable row with one box, and the chip narrows to who still needs Basic', async () => {
    const wrapper = await mountRoster()

    await wrapper.find('[data-test="only-without-basic"]').trigger('click')
    expect(checkboxFor(wrapper, 'D UAT').exists()).toBe(false)

    await wrapper.find('[data-test="select-all"]').setValue(true)
    expect(wrapper.find('[data-test="selected-count"]').text()).toContain('เลือกแล้ว 2 คน')
  })
})

describe('the grant dialog', () => {
  it('says who will be granted and who already holds the tier, then sends ONE request', async () => {
    post.mockResolvedValue({ data: { cert_tier: TIERS[0], granted_user_ids: [3, 4], already_held_user_ids: [2] } })
    const wrapper = await mountRoster()

    for (const name of ['D UAT', 'M UAT', 'L UAT']) await checkboxFor(wrapper, name).setValue(true)
    await wrapper.find('[data-test="bulk-grant"]').trigger('click')

    expect(wrapper.find('[data-test="grant-will"]').text()).toContain('จะอนุมัติ 2 คน')
    expect(wrapper.find('[data-test="grant-skip"]').text()).toContain('D UAT')
    expect(post).not.toHaveBeenCalled()

    await wrapper.find('[data-test="confirm-grant"]').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledTimes(1)
    expect(post).toHaveBeenCalledWith('/user-certifications/bulk', { user_ids: [2, 3, 4], cert_tier_id: 10 })
    expect(wrapper.find('[data-test="grant-result"]').text()).toContain('อนุมัติ Basic ให้ 2 คนแล้ว')
    expect(wrapper.find('[data-test="grant-dialog"]').exists()).toBe(false)
  })

  it('a row without Basic has its own one-agent button', async () => {
    const wrapper = await mountRoster()

    await wrapper.find('[data-test="grant-course"]').trigger('click')

    expect(wrapper.find('[data-test="grant-will"]').text()).toContain('จะอนุมัติ 1 คน')
  })
})
