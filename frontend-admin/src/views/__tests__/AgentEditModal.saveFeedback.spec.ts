/**
 * ADR-052 — AgentEditModal reports every write, and every report quotes the
 * SERVER's answer.
 *
 * The modal closes itself on most writes, so it hands its sentence to the
 * host through `saved` and the host raises the one global dialog after its
 * own reload (see the AgentRosterView / SalesTeamView specs). The audit found
 * that sentence was built from the LOCAL form ("บันทึกข้อมูลของ <typed name>"),
 * that a granted tier emitted no sentence at all, and that a password reset
 * reported itself with a line of inline text. All three are pinned here.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const post = vi.fn()
const put = vi.fn()

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
    post: (...args: unknown[]) => post(...args),
    put: (...args: unknown[]) => put(...args),
    patch: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: FakeApiError,
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import AgentEditModal from '../AgentEditModal.vue'
import { useAuthStore } from '@/stores/auth'
import { saveFeedbackState } from '@/composables/useSaveFeedback'

const HOME = { id: 4, name: 'Thai Life insurance', commission_plan_type: 'unilevel' }
const OTHER = { id: 5, name: 'GENESENN', commission_plan_type: 'unilevel' }

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
  manager_id: null,
  binary_leg: null,
  permissions: { update: true, delete: true, deactivate: true, restore: true, move_company: true },
}

type SavedPayload = { leaderChanged: boolean; successMessage?: string }

async function mountModal() {
  get.mockImplementation(async (path: string) => {
    if (String(path).startsWith('/users/')) return { data: AGENT }

    return { data: [] }
  })

  const wrapper = mount(AgentEditModal, {
    props: {
      agentId: 42,
      roster: [],
      companies: [HOME, OTHER],
      certTiers: [{ id: 10, key: 'basic', name: 'Basic' }],
      certifications: [],
    },
    global: {
      stubs: {
        Icon: true,
        BuddhistDateInput: true,
        PlatformScopeBadge: true,
        LoadingSkeleton: true,
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

const savedPayloads = (w: Awaited<ReturnType<typeof mountModal>>) =>
  (w.emitted('saved') ?? []).map(([p]) => p as SavedPayload)

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  put.mockReset()
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
})

describe('AgentEditModal — ADR-052', () => {
  it('hands the host a sentence naming the SERVER\'s stored name, only after the PUT resolved', async () => {
    let release!: (v: unknown) => void
    put.mockReturnValue(new Promise((r) => { release = r }))
    const wrapper = await mountModal()

    await wrapper.find('[data-test="edit-first-name"]').setValue('  สมชายพิมพ์  ')
    await wrapper.find('[data-test="save-agent"]').trigger('click')
    await flushPromises()
    expect(wrapper.emitted('saved')).toBeUndefined()

    release({ data: { ...AGENT, first_name: 'สมชายพิมพ์', name: 'สมชายพิมพ์ ใจดี (เก็บแล้ว)' } })
    await flushPromises()

    const [payload] = savedPayloads(wrapper)
    expect(payload!.successMessage).toBe('บันทึกข้อมูลของ สมชายพิมพ์ ใจดี (เก็บแล้ว) เรียบร้อยแล้ว')
    expect(wrapper.emitted('close')).toBeTruthy()
    // The host raises the dialog after ITS reload — never the modal, so the
    // save is announced exactly once.
    expect(saveFeedbackState.show).toBe(false)
  })

  it('emits nothing and keeps the error beside the button when the save is refused', async () => {
    put.mockRejectedValue(new FakeApiError(500))
    const wrapper = await mountModal()

    await wrapper.find('[data-test="edit-first-name"]').setValue('สมชายใหม่')
    await wrapper.find('[data-test="save-agent"]').trigger('click')
    await flushPromises()

    expect(wrapper.emitted('saved')).toBeUndefined()
    expect(wrapper.emitted('close')).toBeUndefined()
    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.find('[data-test="edit-footer-error"]').text()).toContain('บันทึกไม่สำเร็จ (500)')
  })

  it('gives a granted tier a sentence of its own, naming the tier the server granted', async () => {
    post.mockResolvedValue({ data: { id: 1, user_id: 42, cert_tier: { id: 10, key: 'basic', name: 'Basic (ระดับต้น)' } } })
    const wrapper = await mountModal()

    await wrapper.find('[data-test="grant-tier"]').trigger('click')
    expect(post).not.toHaveBeenCalled()
    await wrapper.find('[data-test="confirm-yes"]').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/user-certifications', { user_id: 42, cert_tier_id: 10 })
    const [payload] = savedPayloads(wrapper)
    expect(payload!.successMessage).toBe('อนุมัติ Basic (ระดับต้น) ให้ สมชาย ใจดี แล้ว')
    // The grant leaves the modal open — and still raises no dialog of its own.
    expect(wrapper.emitted('close')).toBeUndefined()
  })

  it('reports a password reset through the global dialog instead of an inline line', async () => {
    post.mockResolvedValue({ data: { ...AGENT, name: 'สมชาย ใจดี (server)' } })
    const wrapper = await mountModal()

    await wrapper.find('[data-test="reset-password-input"]').setValue('Temp1234x')
    await wrapper.find('[data-test="submit-reset-password"]').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('ตั้งรหัสผ่านใหม่ให้ สมชาย ใจดี (server) แล้ว')
    expect(wrapper.text()).not.toContain('ตั้งรหัสผ่านใหม่สำเร็จ')
    // The typed password stays in its box on purpose (handed over in person).
    expect((wrapper.find('[data-test="reset-password-input"]').element as HTMLInputElement).value).toBe('Temp1234x')
  })

  it('raises no dialog when the reset is refused', async () => {
    post.mockRejectedValue(new FakeApiError(422, { errors: { password: ['รหัสผ่านต้องมีตัวพิมพ์ใหญ่'] } }))
    const wrapper = await mountModal()

    await wrapper.find('[data-test="reset-password-input"]').setValue('temp12345')
    await wrapper.find('[data-test="submit-reset-password"]').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.find('[data-test="reset-password-error"]').text()).toContain('รหัสผ่านต้องมีตัวพิมพ์ใหญ่')
  })

  it('names the company the SERVER moved the agent to, not the dropdown choice', async () => {
    post.mockResolvedValue({ data: { ...AGENT, company: { id: 5, name: 'GENESENN Co., Ltd.' } } })
    const wrapper = await mountModal()

    await wrapper.find('[data-test="move-company-select"]').setValue('5')
    await wrapper.find('[data-test="submit-move-company"]').trigger('click')
    await flushPromises()

    const [payload] = savedPayloads(wrapper)
    expect(payload!.successMessage).toBe('ย้าย สมชาย ใจดี ไปบริษัท GENESENN Co., Ltd. เรียบร้อยแล้ว')
  })

  it('reports a PARTIAL save when the person was stored but a target was refused, and keeps the failure inline', async () => {
    put.mockResolvedValue({ data: { ...AGENT, first_name: 'สมชายใหม่', name: 'สมชายใหม่ ใจดี' } })
    // Monthly sales target lands; yearly deals target is refused.
    post.mockImplementation(async (_path: string, body: { period: string; metric: string }) => {
      if (body.metric === 'deals') throw new FakeApiError(422)

      return { data: { id: 1 } }
    })
    const wrapper = await mountModal()

    await wrapper.find('[data-test="edit-first-name"]').setValue('สมชายใหม่')
    await wrapper.find('[data-test="target-monthly-sales"]').setValue('50000')
    await wrapper.find('[data-test="target-yearly-deals"]').setValue('12')
    await wrapper.find('[data-test="save-agent"]').trigger('click')
    await flushPromises()

    // The host is told — so it re-reads and raises the dialog with the partial title.
    const [payload] = savedPayloads(wrapper) as Array<SavedPayload & { successTitle?: string }>
    expect(payload!.successTitle).toBe('บันทึกแล้วบางส่วน')
    expect(payload!.successMessage).toContain('บันทึกข้อมูลของ สมชายใหม่ ใจดี แล้ว')
    expect(payload!.successMessage).toContain('เป้าจำนวนดีลรายปี')
    expect(payload!.successMessage).not.toContain('เป้ายอดขายรายเดือน')
    // The modal stays open with the failed target named beside the button.
    expect(wrapper.emitted('close')).toBeUndefined()
    expect(wrapper.find('[data-test="edit-footer-error"]').text()).toContain('เป้าจำนวนดีลรายปี')

    // A retry sends only what did not land: no second PUT, no second sales target.
    put.mockClear()
    post.mockReset()
    post.mockResolvedValue({ data: { id: 2 } })
    await wrapper.find('[data-test="save-agent"]').trigger('click')
    await flushPromises()

    expect(put).not.toHaveBeenCalled()
    expect(post).toHaveBeenCalledTimes(1)
    expect((post.mock.calls[0]![1] as { metric: string }).metric).toBe('deals')
  })

  it('treats a save where NOTHING landed as an ordinary failure — no saved event', async () => {
    post.mockRejectedValue(new FakeApiError(500))
    const wrapper = await mountModal()

    await wrapper.find('[data-test="target-monthly-sales"]').setValue('50000')
    await wrapper.find('[data-test="save-agent"]').trigger('click')
    await flushPromises()

    expect(wrapper.emitted('saved')).toBeUndefined()
    expect(wrapper.find('[data-test="edit-footer-error"]').text()).toContain('บันทึกไม่สำเร็จ')
  })
})
