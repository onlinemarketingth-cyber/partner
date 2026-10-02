/**
 * ADR-052 — ทีมขาย: every write the cards make (grant a tier, grant the
 * team-leader flag, change upline, approve/reject) and every save inside the
 * in-place AgentEditModal ends in the ONE global "saved" dialog (no local
 * <SuccessDialog>), raised after the roster / certifications were re-read,
 * naming what the SERVER returned. A failure raises nothing.
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
          template:
            '<div v-if="show" data-test="confirm" :data-variant="variant" :data-busy="busy ? \'yes\' : \'no\'">'
            + '<p data-test="confirm-title">{{ title }}</p><p data-test="confirm-body">{{ body }}</p>'
            + '<button data-test="confirm-yes" @click="$emit(\'confirm\')">ok</button>'
            + '<button data-test="confirm-no" @click="$emit(\'update:show\', false); $emit(\'cancel\')">no</button></div>',
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

describe('SalesTeamView — ADR-052 saved dialog', () => {
  it('grants a tier, re-reads the certifications, then names the tier the SERVER granted', async () => {
    post.mockImplementation(async () => {
      certs = [{ id: 1, user_id: 1, cert_tier: TIERS[0] }]

      return { data: { id: 1, user_id: 1, cert_tier: { id: 10, key: 'basic', name: 'Basic (ระดับต้น)' } } }
    })
    const wrapper = await mountView()
    await expandFirstRow(wrapper)

    await wrapper.find('[data-test="grant-tier"]').trigger('click')
    expect(post).not.toHaveBeenCalled()
    await wrapper.find('[data-test="confirm-yes"]').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('อนุมัติ Basic (ระดับต้น) ให้ หัวหน้า แล้ว')
    // The re-read list says the tier is held, so the button is gone.
    expect(wrapper.find('[data-test="grant-tier"]').exists()).toBe(false)
  })

  it('raises no dialog when the grant is refused, and shows the error on that card', async () => {
    post.mockRejectedValue(new FakeApiError(500))
    const wrapper = await mountView()
    await expandFirstRow(wrapper)

    await wrapper.find('[data-test="grant-tier"]').trigger('click')
    await wrapper.find('[data-test="confirm-yes"]').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.find('[data-test="grant-error"]').text()).toContain('อนุมัติไม่สำเร็จ (500)')
  })

  it('announces a granted team-leader flag in the words of the flag the server stored', async () => {
    put.mockImplementation(async () => {
      agents = [agent(1, 'หัวหน้า', { is_team_leader: true }), agent(2, 'อิสระ', { is_team_leader: true })]

      return { data: { name: 'อิสระ (server)', is_team_leader: true } }
    })
    const wrapper = await mountView()
    await openTab(wrapper, 'สมาชิกอิสระ')

    await wrapper.find('[data-test="grant-team-leader"]').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/users/2', { is_team_leader: true })
    expect(saveFeedbackState.body).toBe('ให้สิทธิ์หัวหน้าทีมแก่ อิสระ (server) แล้ว')
  })

  it('announces an upline change with both names read from the server', async () => {
    put.mockImplementation(async () => {
      agents = [agent(1, 'หัวหน้า', { is_team_leader: true }), agent(2, 'อิสระ', { manager_id: 1 })]

      return { data: { name: 'อิสระ', manager_id: 1 } }
    })
    const wrapper = await mountView()
    await openTab(wrapper, 'สมาชิกอิสระ')

    await wrapper.find('[data-test="manager-select"]').setValue('1')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/users/2', { manager_id: 1 })
    expect(saveFeedbackState.body).toBe('เปลี่ยนหัวหน้าของ อิสระ เป็น หัวหน้า แล้ว')
  })

  it('announces an approval after the roster reload', async () => {
    agents = [agent(1, 'หัวหน้า', { is_team_leader: true }), agent(3, 'ผู้สมัคร', { manager_id: 1, agent_approval_status: 'pending' })]
    put.mockImplementation(async () => {
      agents = [agent(1, 'หัวหน้า', { is_team_leader: true }), agent(3, 'ผู้สมัคร', { manager_id: 1 })]

      return { data: { name: 'ผู้สมัคร (server)' } }
    })
    const wrapper = await mountView()
    await openTab(wrapper, 'รออนุมัติเข้าทีม')

    await wrapper.find('[data-test="approve-agent"]').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/agent-approvals/3/approve')
    expect(saveFeedbackState.body).toBe('อนุมัติ ผู้สมัคร (server) แล้ว')
    expect(wrapper.find('[data-test="approve-agent"]').exists()).toBe(false)
  })

  it('shows the edit modal\'s sentence once, after the roster AND certifications were re-read, with no local dialog', async () => {
    const wrapper = await mountView()
    expect(wrapper.findComponent({ name: 'SuccessDialog' }).exists()).toBe(false)
    const readsBefore = get.mock.calls.length

    await wrapper.find('[data-test="modal-emit-saved"]').trigger('click')
    await flushPromises()

    const reads = get.mock.calls.slice(readsBefore).map(([p]) => String(p))
    expect(reads.some((p) => p.startsWith('/sales-team-overview'))).toBe(true)
    expect(reads.some((p) => p.startsWith('/user-certifications'))).toBe(true)
    expect(saveFeedbackState.count).toBe(1)
    expect(saveFeedbackState.body).toBe('บันทึกข้อมูลของ หัวหน้า (server) เรียบร้อยแล้ว')
  })
})

/*
 * 2026-10-02 (owner decision) — the card's ยืนยันปฏิเสธ only ASKS. The page's
 * ConfirmDialog names the agent and quotes the reason; its confirm sends the
 * same PUT the card used to trigger directly.
 */
describe('SalesTeamView — rejecting a pending agent asks a ConfirmDialog first', () => {
  async function typeReasonAndPress(reason: string) {
    agents = [agent(1, 'หัวหน้า', { is_team_leader: true }), agent(3, 'ผู้สมัคร', { manager_id: 1, agent_approval_status: 'pending' })]
    const wrapper = await mountView()
    await openTab(wrapper, 'รออนุมัติเข้าทีม')
    await wrapper.find('[data-test="reject-agent"]').trigger('click')
    await wrapper.find('[data-test="reject-agent-reason"]').setValue(reason)
    await wrapper.find('[data-test="reject-agent-submit"]').trigger('click')
    await flushPromises()

    return wrapper
  }

  it('sends nothing on the card button and opens a danger dialog quoting the reason', async () => {
    const wrapper = await typeReasonAndPress('ยังไม่ผ่านสัมภาษณ์')

    expect(put).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="confirm"]').attributes('data-variant')).toBe('danger')
    expect(wrapper.get('[data-test="confirm-title"]').text()).toBe('ยืนยันไม่อนุมัติ')
    expect(wrapper.get('[data-test="confirm-body"]').text()).toBe('ไม่อนุมัติ ผู้สมัคร — เหตุผล: ยังไม่ผ่านสัมภาษณ์')
  })

  it('cancel sends nothing and leaves the card\'s reason box open with the text', async () => {
    const wrapper = await typeReasonAndPress('ยังไม่ผ่านสัมภาษณ์')

    await wrapper.get('[data-test="confirm-no"]').trigger('click')
    await flushPromises()

    expect(put).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="confirm"]').exists()).toBe(false)
    expect((wrapper.get('[data-test="reject-agent-reason"]').element as HTMLInputElement).value).toBe('ยังไม่ผ่านสัมภาษณ์')
  })

  it('confirm sends the card\'s exact payload once, then the saved dialog after the reload', async () => {
    put.mockImplementation(async () => {
      agents = [agent(1, 'หัวหน้า', { is_team_leader: true })]

      return { data: { name: 'ผู้สมัคร (server)' } }
    })
    const wrapper = await typeReasonAndPress('ยังไม่ผ่านสัมภาษณ์')

    await wrapper.get('[data-test="confirm-yes"]').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledTimes(1)
    expect(put).toHaveBeenCalledWith('/agent-approvals/3/reject', { reason: 'ยังไม่ผ่านสัมภาษณ์' })
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('บันทึกว่าไม่อนุมัติ ผู้สมัคร (server) แล้ว')
    expect(wrapper.find('[data-test="confirm"]').exists()).toBe(false)
  })

  it('an empty reason is still sent as undefined, and the dialog says none was given', async () => {
    put.mockResolvedValue({ data: { name: 'ผู้สมัคร' } })
    const wrapper = await typeReasonAndPress('')

    expect(wrapper.get('[data-test="confirm-body"]').text()).toBe('ไม่อนุมัติ ผู้สมัคร — เหตุผล: ไม่ระบุ')
    await wrapper.get('[data-test="confirm-yes"]').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/agent-approvals/3/reject', { reason: undefined })
  })

  it('is busy while sending; a refusal closes the dialog, keeps the card\'s error and raises no saved dialog', async () => {
    let release: (e: unknown) => void = () => {}
    put.mockImplementation(() => new Promise((_, reject) => { release = reject }))
    const wrapper = await typeReasonAndPress('ยังไม่ผ่านสัมภาษณ์')

    await wrapper.get('[data-test="confirm-yes"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-test="confirm"]').attributes('data-busy')).toBe('yes')

    release(new FakeApiError(409))
    await flushPromises()

    expect(wrapper.find('[data-test="confirm"]').exists()).toBe(false)
    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.text()).toContain('ปฏิเสธไม่สำเร็จ (409)')
  })
})
