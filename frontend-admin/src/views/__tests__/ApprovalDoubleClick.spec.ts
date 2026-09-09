/**
 * 2026-09-08 (human: "ผมอนุมัติแล้ว error แต่อนุมัติได้เช็คหน่อยครับ").
 *
 * Both halves of that sentence were true, and they were the same event.
 *
 * The approval worked. The 422 came from a SECOND request for the same person
 * — AgentApprovalService::assertPending() refuses to decide a registration
 * twice, and answers in words: "ผู้ใช้นี้ไม่ได้อยู่ในสถานะรออนุมัติแล้ว
 * (อาจถูกดำเนินการไปแล้วโดยผู้ดูแลคนอื่น)". That sentence is the whole answer
 * to the report, and this screen was throwing it away in favour of
 * `อนุมัติไม่สำเร็จ (422)` — a flat denial of something that had just
 * succeeded.
 *
 * Three things made the second click easy, and each has a test here: nothing
 * stopped it, the row stayed put afterwards so the screen contradicted itself,
 * and the message hid the reason.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const put = vi.fn()

const { FakeApiError } = vi.hoisted(() => ({
  FakeApiError: class extends Error {
    constructor(
      public status: number,
      public body: unknown,
      message?: string,
    ) {
      super(message ?? `API error ${status}`)
    }
  },
}))

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    put: (...args: unknown[]) => put(...args),
    post: vi.fn(),
    delete: vi.fn(),
    patch: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: FakeApiError,
}))

vi.mock('vue-router', async () => {
  const actual = await vi.importActual<typeof import('vue-router')>('vue-router')

  return { ...actual, useRoute: () => ({ query: {} }), useRouter: () => ({ push: vi.fn(), replace: vi.fn() }) }
})

import AgentApprovalsView from '../AgentApprovalsView.vue'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'

/** The exact sentence the server sends when a decision is sent twice. */
const ALREADY_DECIDED = 'ผู้ใช้นี้ไม่ได้อยู่ในสถานะรออนุมัติแล้ว (อาจถูกดำเนินการไปแล้วโดยผู้ดูแลคนอื่น)'

const PENDING = {
  id: 7,
  name: 'kreangyot ohuyhannapa',
  email: 'ikenyaa+1298@gmail.com',
  phone: '096361565',
  role: 'agent',
  company: { id: 5, name: 'GENESENN' },
  is_active: true,
  is_team_leader: false,
  agent_approval_status: 'pending',
  registered_via: 'email',
  email_verified: false,
  has_passed_basic_cert: false,
  created_at: '2026-09-07T06:03:00Z',
}

/** Rows the queue answers with; the array is swapped between calls. */
let queue: unknown[] = []

function mockQueue() {
  get.mockImplementation(async (path: string) => {
    if (String(path).startsWith('/companies')) return { data: [] }

    return { data: queue, meta: { last_page: 1 } }
  })
}

async function mountView() {
  const wrapper = mount(AgentApprovalsView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot name="actions" /><slot name="tabs" /><slot /></div>' },
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
        CompanyScopeNotice: true,
        RouterLink: { props: ['to'], template: '<a><slot /></a>' },
      },
    },
  })
  await flushPromises()

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountView>>

const approveBtn = (w: Wrapper) => w.find('[data-test="approve"]')

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  put.mockResolvedValue({ data: {} })
  queue = [PENDING]
  mockQueue()

  const auth = useAuthStore()
  auth.user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
  useActiveCompanyStore().setCompany(null)
})

describe('AgentApprovalsView — one click is one decision', () => {
  it('sends the approval once even when the button is pressed twice', async () => {
    /*
     * The report, reproduced. The row does not change until the reload lands,
     * so the natural response to "nothing happened yet" is another press —
     * and the second press is what the server refuses.
     */
    let resolveApprove: (v: unknown) => void = () => {}
    put.mockImplementation(() => new Promise((r) => { resolveApprove = r }))

    const wrapper = await mountView()
    await approveBtn(wrapper).trigger('click')
    await approveBtn(wrapper).trigger('click')

    expect(put).toHaveBeenCalledTimes(1)

    resolveApprove({ data: {} })
    await flushPromises()
  })

  it('disables the button while the decision is in the air', async () => {
    put.mockImplementation(() => new Promise(() => {}))

    const wrapper = await mountView()
    await approveBtn(wrapper).trigger('click')

    expect(approveBtn(wrapper).attributes('disabled')).toBeDefined()
    expect(approveBtn(wrapper).text()).toContain('กำลังอนุมัติ')
  })
})

describe('AgentApprovalsView — a refusal explains itself', () => {
  it('shows the server\'s sentence instead of the status code', async () => {
    /*
     * The heart of the report. A code cannot tell "already decided" from "we
     * lost your click"; the sentence behind it can, and ApiError.message has
     * carried it since 2026-07-20 — this screen just never read it.
     */
    put.mockRejectedValue(new FakeApiError(422, null, ALREADY_DECIDED))

    const wrapper = await mountView()
    await approveBtn(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('ถูกดำเนินการไปแล้ว')
    expect(wrapper.text()).not.toContain('อนุมัติไม่สำเร็จ (422)')
  })

  it('still falls back to the code when the server sent no sentence', async () => {
    // A 500 or a gateway error carries nothing worth reading; inventing a
    // reason there would be worse than showing the number.
    put.mockRejectedValue(new FakeApiError(500, null))

    const wrapper = await mountView()
    await approveBtn(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('อนุมัติไม่สำเร็จ (500)')
  })

  it('reloads the queue after a refusal, so the screen stops contradicting itself', async () => {
    /*
     * Before this, only the success path reloaded. So the failure left a
     * banner saying the approval failed sitting directly above a row saying
     * the person is still waiting — for someone who was in fact already
     * approved. Reloading turns the refusal into the truth: the row is gone,
     * and the message says why.
     */
    put.mockImplementation(async () => {
      queue = []                       // the world moved before we asked
      throw new FakeApiError(422, null, ALREADY_DECIDED)
    })

    const wrapper = await mountView()
    await approveBtn(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.text()).not.toContain('kreangyot ohuyhannapa')
    expect(wrapper.text()).toContain('ถูกดำเนินการไปแล้ว')
  })

  it('clears a previous error when a new decision starts', async () => {
    // Otherwise the banner from the last refusal sits over a fresh, working
    // action and reads as if that one failed too.
    put.mockRejectedValueOnce(new FakeApiError(422, null, ALREADY_DECIDED))

    const wrapper = await mountView()
    await approveBtn(wrapper).trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('ถูกดำเนินการไปแล้ว')

    queue = [PENDING]
    put.mockResolvedValue({ data: {} })
    await approveBtn(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.text()).not.toContain('ถูกดำเนินการไปแล้ว')
  })
})
