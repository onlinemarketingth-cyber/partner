/**
 * 2026-09-19 — the control that was missing for the whole life of the Binary
 * plan.
 *
 * `users.binary_leg` shipped with the engine (ADR-006 Round 4) and no screen
 * ever offered it, so outside a test factory the column could only be null —
 * and BinaryCommissionService::creditVolume() has a leg to credit or it
 * returns. A Binary company therefore worked exactly as written and paid
 * nobody, for ever, with nothing reporting why.
 *
 * Two things are worth pinning here rather than only on the backend:
 *
 *   · It appears on a Binary company and NOWHERE ELSE. UpdateUserRequest
 *     refuses the field on any other plan, so a control that showed up there
 *     would be one the admin could set and never save.
 *   · It is only SENT when it changed. This modal PUTs a patch, and a field
 *     that rides along unchanged on every save is how a value gets rewritten
 *     by somebody who only meant to fix a phone number.
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

const BINARY_CO = { id: 4, name: 'Thai Life insurance', slug: 'tli', commission_plan_type: 'binary' }
const UNILEVEL_CO = { id: 4, name: 'Thai Life insurance', slug: 'tli', commission_plan_type: 'unilevel' }

const AGENT = {
  id: 42,
  name: 'สมชาย ใจดี',
  first_name: 'สมชาย',
  last_name: 'ใจดี',
  email: 'somchai@example.com',
  phone: null,
  role: 'agent',
  company: { id: 4, name: 'Thai Life insurance' },
  is_active: true,
  is_team_leader: false,
  manager_id: 90,
  manager: { id: 90, name: 'หัวหน้าสาย' },
  manager_is_commission_house_account: false,
  is_commission_house_account: false,
  binary_leg: null,
  permissions: { update: true, delete: true, deactivate: true, restore: true, move_company: true },
}

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  put.mockReset()
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
})

/** The modal saves from a button, not a <form> submit. */
async function saveAgent(wrapper: Awaited<ReturnType<typeof mountModal>>) {
  const save = wrapper.findAll('button').find((b) => b.text().includes('บันทึก'))
  if (!save) throw new Error('no save button on the edit modal')
  await save.trigger('click')
  await flushPromises()
}

async function mountModal(company: typeof BINARY_CO, subject: Record<string, unknown> = AGENT) {
  get.mockImplementation(async (path: string) => {
    if (String(path).startsWith('/users/')) return { data: subject }
    if (String(path).startsWith('/companies')) return { data: [company] }

    return { data: [] }
  })

  const wrapper = mount(AgentEditModal, {
    props: { agentId: 42, roster: [], companies: [company] },
    global: {
      stubs: { Icon: true, ConfirmDialog: true, BuddhistDateInput: true, PlatformScopeBadge: true, LoadingSkeleton: true },
    },
  })
  await flushPromises()

  return wrapper
}

describe('placing an agent on a Binary leg', () => {
  it('offers the left/right choice on a company that runs Binary', async () => {
    const wrapper = await mountModal(BINARY_CO)

    expect(wrapper.find('[data-test="binary-leg-field"]').exists()).toBe(true)
    const options = wrapper.get('[data-test="binary-leg-select"]').findAll('option').map((o) => o.text())
    expect(options).toEqual(['ยังไม่ได้เลือกขา', 'ขาซ้าย', 'ขาขวา'])
  })

  it('says out loud what an unplaced agent costs', async () => {
    // "ยังไม่ได้เลือกขา" on its own reads like an optional field. It is not:
    // an unplaced agent's sales reach no leg at all and the matching cycle
    // never sees them.
    const wrapper = await mountModal(BINARY_CO)

    expect(wrapper.get('[data-test="binary-leg-field"]').text())
      .toContain('ยังไม่เลือก = ยอดไม่เข้าขาไหนเลย')
  })

  it('hides the control entirely on any other plan', async () => {
    const wrapper = await mountModal(UNILEVEL_CO)

    expect(wrapper.find('[data-test="binary-leg-field"]').exists()).toBe(false)
  })

  it('preselects the placement the agent already has', async () => {
    const wrapper = await mountModal(BINARY_CO, { ...AGENT, binary_leg: 'right' })

    expect((wrapper.get('[data-test="binary-leg-select"]').element as HTMLSelectElement).value).toBe('right')
  })

  it('sends the leg only when it actually changed', async () => {
    put.mockResolvedValue({ data: { ...AGENT, binary_leg: 'left' } })
    const wrapper = await mountModal(BINARY_CO)

    await wrapper.get('[data-test="binary-leg-select"]').setValue('left')
    await saveAgent(wrapper)
    await flushPromises()

    expect(put).toHaveBeenCalled()
    const [, payload] = put.mock.calls[0] as [string, Record<string, unknown>]
    expect(payload.binary_leg).toBe('left')
  })

  it('leaves the leg out of a save that changed something else', async () => {
    // The real risk: somebody opens the modal to fix a name and the placement
    // rides along in the patch, quietly re-sent. The modal builds a diff, and
    // this pins that the leg is part of the diff rather than the payload.
    put.mockResolvedValue({ data: { ...AGENT, binary_leg: 'right' } })
    const wrapper = await mountModal(BINARY_CO, { ...AGENT, binary_leg: 'right' })

    const firstName = wrapper.findAll('input[type="text"]')[0]
    expect(firstName).toBeDefined()
    await firstName!.setValue('สมชายใหม่')
    await saveAgent(wrapper)

    expect(put).toHaveBeenCalled()
    const [, payload] = put.mock.calls[0] as [string, Record<string, unknown>]
    expect(payload.first_name).toBe('สมชายใหม่')
    expect(payload).not.toHaveProperty('binary_leg')
  })
})
