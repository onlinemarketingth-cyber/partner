/**
 * ADR-052 — SalesTeamCard, the card a ทีมขาย row opens into. Its writes run in
 * SalesTeamView (injected), so these tests mount the real view and the real
 * card together and drive the card's own controls.
 *
 * The card-specific fix pinned here: the upline <select> is bound with
 * `:value`, so after a REFUSED change the prop never moved, Vue patched
 * nothing, and the select went on showing the rejected choice as if stored.
 * It now snaps back to the stored value once the write settles.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const put = vi.fn()
const post = vi.fn()

const { FakeApiError } = vi.hoisted(() => ({
  FakeApiError: class extends Error {
    constructor(
      public status: number,
      public body: unknown = {},
    ) {
      super(`API error ${status}`)
    }
  },
}))

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    put: (...args: unknown[]) => put(...args),
    post: (...args: unknown[]) => post(...args),
    patch: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
  },
  ApiError: FakeApiError,
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  useRoute: () => ({ query: {} }),
  RouterLink: { props: ['to'], template: '<a><slot /></a>' },
}))

// The modal is another file's surface; here it only has to emit `saved`.
vi.mock('../AgentEditModal.vue', async () => {
  const { h } = await import('vue')

  return {
    default: {
      name: 'AgentEditModalStub',
      props: ['agentId'],
      emits: ['saved', 'close'],
      setup(_: unknown, { emit }: { emit: (e: string, p: unknown) => void }) {
        return () =>
          h('button', {
            'data-test': 'modal-emit-saved',
            onClick: () => emit('saved', { leaderChanged: true, successMessage: 'บันทึกข้อมูลของ หัวหน้า (server) เรียบร้อยแล้ว' }),
          })
      },
    },
  }
})

import SalesTeamView from '../SalesTeamView.vue'
import { saveFeedbackState } from '@/composables/useSaveFeedback'

function agent(id: number, name: string, over: Record<string, unknown> = {}) {
  return {
    agent_id: id,
    agent_name: name,
    agent_email: `a${id}@x.co`,
    agent_phone: null,
    manager_id: null,
    is_team_leader: false,
    avatar_url: null,
    client_count: 0,
    deals_by_stage: {},
    total_deals: 0,
    closed_deals: 0,
    closed_deals_without_order: 0,
    conversion: 0,
    total_sales_satang: 0,
    total_commission_satang: 0,
    agent_approval_status: 'approved',
    ...over,
  }
}

const TIERS = [{ id: 10, key: 'basic', name: 'Basic' }]
let agents: unknown[] = []
let certs: unknown[] = []

async function mountView() {
  const wrapper = mount(SalesTeamView, {
    attachTo: document.body,
    global: {
      stubs: {
        Icon: true,
        ConfirmDialog: {
          props: ['show', 'title', 'body', 'variant', 'busy'],
          template: '<div v-if="show" data-test="confirm"><button data-test="confirm-yes" @click="$emit(\'confirm\')">ok</button></div>',
        },
      },
    },
  })
  await flushPromises()

  return wrapper
}

async function openTab(wrapper: Awaited<ReturnType<typeof mountView>>, label: string) {
  const tab = wrapper.findAll('button').find((b) => b.text().startsWith(label))!
  await tab.trigger('click')
  await flushPromises()
  await expandFirstRow(wrapper)
}

/** The table's rows open into the SalesTeamCard that holds every control. */
async function expandFirstRow(wrapper: Awaited<ReturnType<typeof mountView>>) {
  await wrapper.find('tr.cursor-pointer').trigger('click')
  await flushPromises()
}

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  post.mockReset()
  agents = [agent(1, 'หัวหน้า', { is_team_leader: true }), agent(2, 'อิสระ')]
  certs = []
  get.mockImplementation(async (path: string) => {
    const p = String(path)
    if (p.startsWith('/sales-team-overview')) return { data: agents, meta: { clients_total: 0 } }
    if (p.startsWith('/cert-tiers')) return { data: TIERS }
    if (p.startsWith('/user-certifications')) return { data: certs }
    throw new Error(`unexpected GET ${p}`)
  })
})

describe('SalesTeamCard — ADR-052', () => {
  it('snaps the upline select back to the stored value when the change is refused, with no dialog', async () => {
    put.mockRejectedValue(new FakeApiError(422, { errors: { manager_id: ['would create a management cycle'] } }))
    const wrapper = await mountView()
    await openTab(wrapper, 'สมาชิกอิสระ')

    const select = wrapper.find('[data-test="manager-select"]')
    await select.setValue('1')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/users/2', { manager_id: 1 })
    expect(saveFeedbackState.show).toBe(false)
    expect((select.element as HTMLSelectElement).value).toBe('')
    expect(wrapper.find('[data-test="structure-error"]').text()).toContain('would create a management cycle')
  })

  it('names the upline the SERVER stored (not the one picked) once the tree was re-read', async () => {
    agents = [
      agent(1, 'หัวหน้า', { is_team_leader: true }),
      agent(2, 'อิสระ'),
      agent(4, 'หัวหน้าสำรอง', { is_team_leader: true }),
    ]
    // The admin picks หัวหน้า (1); the server stores หัวหน้าสำรอง (4).
    put.mockImplementation(async () => {
      agents = [
        agent(1, 'หัวหน้า', { is_team_leader: true }),
        agent(2, 'อิสระ', { manager_id: 4, is_team_leader: true }),
        agent(4, 'หัวหน้าสำรอง', { is_team_leader: true }),
      ]

      return { data: { name: 'อิสระ', manager_id: 4 } }
    })
    const wrapper = await mountView()
    await openTab(wrapper, 'สมาชิกอิสระ')

    const select = wrapper.find('[data-test="manager-select"]')
    await select.setValue('1')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('เปลี่ยนหัวหน้าของ อิสระ เป็น หัวหน้าสำรอง แล้ว')
    // Re-parented, the agent has left the สมาชิกอิสระ tab — the tree was re-read.
    expect(wrapper.find('[data-test="manager-select"]').exists()).toBe(false)
  })

  it('grants the team-leader flag from the card and raises the dialog once', async () => {
    put.mockImplementation(async () => {
      agents = [agent(1, 'หัวหน้า', { is_team_leader: true }), agent(2, 'อิสระ', { is_team_leader: true })]

      return { data: { name: 'อิสระ', is_team_leader: true } }
    })
    const wrapper = await mountView()
    await openTab(wrapper, 'สมาชิกอิสระ')

    await wrapper.find('[data-test="grant-team-leader"]').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.count).toBe(1)
    expect(saveFeedbackState.body).toBe('ให้สิทธิ์หัวหน้าทีมแก่ อิสระ แล้ว')
  })

  it('keeps the card\'s approval error, with no dialog, when an approval is refused', async () => {
    agents = [agent(1, 'หัวหน้า', { is_team_leader: true }), agent(3, 'ผู้สมัคร', { manager_id: 1, agent_approval_status: 'pending' })]
    put.mockRejectedValue(new FakeApiError(409))
    const wrapper = await mountView()
    await openTab(wrapper, 'รออนุมัติเข้าทีม')

    await wrapper.find('[data-test="approve-agent"]').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.text()).toContain('อนุมัติไม่สำเร็จ (409)')
  })
})
