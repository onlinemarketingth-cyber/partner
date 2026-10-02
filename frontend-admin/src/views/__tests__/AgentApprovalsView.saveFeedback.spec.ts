/**
 * ADR-052 — รออนุมัติ: approve / reject / revoke each end in the one global
 * "saved" dialog, raised only after the decision resolved AND the queue was
 * re-read, naming the person as the SERVER returned them; a refusal raises
 * nothing and keeps the server's sentence on screen.
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
import { SAVED_BUT_STALE_BODY, saveFeedbackState } from '@/composables/useSaveFeedback'

function applicant(over: Record<string, unknown> = {}) {
  return {
    id: 7,
    name: 'ผู้สมัคร ใหม่',
    email: 'new@example.test',
    phone: null,
    role: 'agent',
    company: { id: 5, name: 'GENESENN' },
    is_active: true,
    is_team_leader: false,
    agent_approval_status: 'pending',
    registered_via: 'email',
    email_verified: true,
    has_passed_basic_cert: false,
    created_at: '2026-09-07T06:03:00Z',
    ...over,
  }
}

let queue: unknown[] = []
let failQueueRead = false

function deferred<T>() {
  let resolve!: (v: T) => void
  const promise = new Promise<T>((res) => {
    resolve = res
  })

  return { promise, resolve }
}

async function mountView() {
  const wrapper = mount(AgentApprovalsView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot /></div>' },
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

/** The ConfirmDialog currently open (it renders with `v-if`), if any. */
function openDialog(w: Wrapper) {
  return w.findAllComponents({ name: 'ConfirmDialog' }).find((d) => d.props('show') === true)
}

/** Press the open dialog's confirm (its last button) or cancel (its first). */
async function answerDialog(w: Wrapper, answer: 'confirm' | 'cancel') {
  const dialog = openDialog(w)
  expect(dialog, 'an open ConfirmDialog').toBeDefined()
  const buttons = dialog!.findAll('button')
  await (answer === 'confirm' ? buttons[buttons.length - 1] : buttons[0])!.trigger('click')
  await flushPromises()
}

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  queue = [applicant()]
  failQueueRead = false
  get.mockImplementation(async (path: string) => {
    if (String(path).startsWith('/companies')) return { data: [] }
    if (failQueueRead) throw new FakeApiError(500, null)

    return { data: queue, meta: { last_page: 1 } }
  })
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
  useActiveCompanyStore().setCompany(null)
})

describe('AgentApprovalsView — ADR-052 saved dialog', () => {
  it('raises the dialog only after the approval resolved and the queue was re-read, with the SERVER\'s name', async () => {
    const pending = deferred<{ data: unknown }>()
    put.mockReturnValue(pending.promise)
    const wrapper = await mountView()

    await wrapper.find('[data-test="approve"]').trigger('click')
    await flushPromises()
    expect(saveFeedbackState.show).toBe(false)

    queue = []
    pending.resolve({ data: applicant({ name: 'ผู้สมัคร ใหม่ (ชื่อจริง)', agent_approval_status: 'approved' }) })
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('อนุมัติ ผู้สมัคร ใหม่ (ชื่อจริง) แล้ว')
    expect(wrapper.find('[data-test="approve"]').exists()).toBe(false)
  })

  it('keeps the server\'s refusal on screen and raises no dialog', async () => {
    put.mockRejectedValue(new FakeApiError(422, null, 'ผู้ใช้นี้ไม่ได้อยู่ในสถานะรออนุมัติแล้ว'))
    const wrapper = await mountView()

    await wrapper.find('[data-test="approve"]').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.text()).toContain('ผู้ใช้นี้ไม่ได้อยู่ในสถานะรออนุมัติแล้ว')
  })

  it('announces a rejection after the reload', async () => {
    put.mockImplementation(async () => {
      queue = []

      return { data: applicant({ agent_approval_status: 'rejected' }) }
    })
    const wrapper = await mountView()

    await wrapper.find('[data-test="reject"]').trigger('click')
    await wrapper.find('[data-test="submit-reject"]').trigger('click')
    // 2026-10-02 — the panel's button asks first; the dialog's confirm sends.
    await answerDialog(wrapper, 'confirm')

    expect(put).toHaveBeenCalledWith('/agent-approvals/7/reject', { reason: undefined })
    expect(saveFeedbackState.body).toContain('ปฏิเสธ ผู้สมัคร ใหม่ แล้ว')
    expect(wrapper.find('[data-test="submit-reject"]').exists()).toBe(false)
  })

  it('announces a revoked approval from the approved tab', async () => {
    queue = [applicant({ agent_approval_status: 'approved', approval_source: 'team_leader' })]
    put.mockImplementation(async () => {
      queue = []

      return { data: applicant({ agent_approval_status: 'rejected' }) }
    })
    const wrapper = await mountView()

    const approvedTab = wrapper.findAll('button').find((b) => b.text() === 'อนุมัติแล้ว')!
    await approvedTab.trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="revoke"]').trigger('click')
    await wrapper.find('[data-test="submit-revoke"]').trigger('click')
    await answerDialog(wrapper, 'confirm')

    expect(put).toHaveBeenCalledWith('/agent-approvals/7/revoke', { reason: undefined })
    expect(saveFeedbackState.body).toContain('เพิกถอนการอนุมัติของ ผู้สมัคร ใหม่ แล้ว')
  })

  it('says the screen may be behind when the decision landed but the queue could not be re-read', async () => {
    put.mockImplementation(async () => {
      failQueueRead = true

      return { data: applicant({ agent_approval_status: 'approved' }) }
    })
    const wrapper = await mountView()

    await wrapper.find('[data-test="approve"]').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe(SAVED_BUT_STALE_BODY)
  })
})

/*
 * 2026-10-02 (owner decision) — reject and revoke are typed into the row's
 * panel, then ASKED in a ConfirmDialog that quotes the reason; only the
 * dialog's confirm sends, and it sends exactly what the panel used to.
 */
describe('AgentApprovalsView — reject / revoke ask a ConfirmDialog first', () => {
  const REJECT_REASON = 'input[placeholder="เหตุผล (ไม่บังคับ)"]'
  const REVOKE_REASON = 'input[placeholder="เหตุผล (ไม่บังคับ — ผู้ใช้จะเห็นเหตุผลนี้)"]'

  async function openRejectPanel(reason: string) {
    const wrapper = await mountView()
    await wrapper.find('[data-test="reject"]').trigger('click')
    await wrapper.find(REJECT_REASON).setValue(reason)
    await wrapper.find('[data-test="submit-reject"]').trigger('click')
    await flushPromises()

    return wrapper
  }

  async function openRevokePanel(reason: string) {
    queue = [applicant({ agent_approval_status: 'approved', approval_source: 'team_leader' })]
    const wrapper = await mountView()
    await wrapper.findAll('button').find((b) => b.text() === 'อนุมัติแล้ว')!.trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="revoke"]').trigger('click')
    await wrapper.find(REVOKE_REASON).setValue(reason)
    await wrapper.find('[data-test="submit-revoke"]').trigger('click')
    await flushPromises()

    return wrapper
  }

  it('reject: the panel button sends nothing and opens a danger dialog naming the person and quoting the reason', async () => {
    const wrapper = await openRejectPanel('เอกสารไม่ครบ')

    expect(put).not.toHaveBeenCalled()
    const dialog = openDialog(wrapper)!
    expect(dialog.props('variant')).toBe('danger')
    expect(dialog.props('title')).toBe('ยืนยันปฏิเสธ')
    expect(dialog.props('body')).toBe('ปฏิเสธ ผู้สมัคร ใหม่ — เหตุผล: เอกสารไม่ครบ')
  })

  it('reject: cancel sends nothing and keeps the panel open with the reason', async () => {
    const wrapper = await openRejectPanel('เอกสารไม่ครบ')

    await answerDialog(wrapper, 'cancel')

    expect(put).not.toHaveBeenCalled()
    expect(openDialog(wrapper)).toBeUndefined()
    expect((wrapper.find(REJECT_REASON).element as HTMLInputElement).value).toBe('เอกสารไม่ครบ')
    expect(saveFeedbackState.show).toBe(false)
  })

  it('reject: confirm sends the panel\'s exact payload, then the saved dialog', async () => {
    put.mockImplementation(async () => {
      queue = []

      return { data: applicant({ agent_approval_status: 'rejected' }) }
    })
    const wrapper = await openRejectPanel('เอกสารไม่ครบ')

    await answerDialog(wrapper, 'confirm')

    expect(put).toHaveBeenCalledTimes(1)
    expect(put).toHaveBeenCalledWith('/agent-approvals/7/reject', { reason: 'เอกสารไม่ครบ' })
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('ปฏิเสธ ผู้สมัคร ใหม่ แล้ว')
    expect(openDialog(wrapper)).toBeUndefined()
  })

  it('reject: the dialog is busy while the request is in flight', async () => {
    const pending = deferred<{ data: unknown }>()
    put.mockReturnValue(pending.promise)
    const wrapper = await openRejectPanel('เอกสารไม่ครบ')

    await answerDialog(wrapper, 'confirm')
    expect(openDialog(wrapper)!.props('busy')).toBe(true)
    expect(saveFeedbackState.show).toBe(false)

    queue = []
    pending.resolve({ data: applicant({ agent_approval_status: 'rejected' }) })
    await flushPromises()

    expect(openDialog(wrapper)).toBeUndefined()
    expect(saveFeedbackState.show).toBe(true)
  })

  it('reject: a refusal closes the dialog, shows the server\'s sentence and raises no saved dialog', async () => {
    put.mockRejectedValue(new FakeApiError(422, null, 'ผู้ใช้นี้ไม่ได้อยู่ในสถานะรออนุมัติแล้ว'))
    const wrapper = await openRejectPanel('เอกสารไม่ครบ')

    await answerDialog(wrapper, 'confirm')

    expect(openDialog(wrapper)).toBeUndefined()
    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.text()).toContain('ผู้ใช้นี้ไม่ได้อยู่ในสถานะรออนุมัติแล้ว')
  })

  it('reject: says when no reason was given instead of quoting a blank', async () => {
    const wrapper = await openRejectPanel('')

    expect(openDialog(wrapper)!.props('body')).toBe('ปฏิเสธ ผู้สมัคร ใหม่ — เหตุผล: ไม่ระบุ')
  })

  it('revoke: the panel button sends nothing and opens a danger dialog quoting the reason', async () => {
    const wrapper = await openRevokePanel('อนุมัติผิดคน')

    expect(put).not.toHaveBeenCalled()
    const dialog = openDialog(wrapper)!
    expect(dialog.props('variant')).toBe('danger')
    expect(dialog.props('title')).toBe('ยืนยันเพิกถอนการอนุมัติ')
    expect(dialog.props('body')).toContain('เพิกถอนการอนุมัติของ ผู้สมัคร ใหม่')
    expect(dialog.props('body')).toContain('เหตุผล: อนุมัติผิดคน')
  })

  it('revoke: cancel sends nothing and keeps the reason in the panel', async () => {
    const wrapper = await openRevokePanel('อนุมัติผิดคน')

    await answerDialog(wrapper, 'cancel')

    expect(put).not.toHaveBeenCalled()
    expect((wrapper.find(REVOKE_REASON).element as HTMLInputElement).value).toBe('อนุมัติผิดคน')
  })

  it('revoke: confirm sends the panel\'s exact payload, then the saved dialog', async () => {
    put.mockImplementation(async () => {
      queue = []

      return { data: applicant({ agent_approval_status: 'rejected' }) }
    })
    const wrapper = await openRevokePanel('อนุมัติผิดคน')

    await answerDialog(wrapper, 'confirm')

    expect(put).toHaveBeenCalledTimes(1)
    expect(put).toHaveBeenCalledWith('/agent-approvals/7/revoke', { reason: 'อนุมัติผิดคน' })
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('เพิกถอนการอนุมัติของ ผู้สมัคร ใหม่ แล้ว')
  })
})
