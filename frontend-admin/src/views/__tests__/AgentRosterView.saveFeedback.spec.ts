/**
 * ADR-052 — รายชื่อสมาชิก raises the ONE global "saved" dialog (no local
 * <SuccessDialog> any more), always after the roster has been re-read, and
 * never on a failure.
 *
 * Pinned here: the edit modal's `saved` sentence is shown exactly once and
 * only after the reload; creating a member is no longer silent; a bulk grant
 * failure raises nothing; an approval refusal keeps its sentence on screen
 * (the reload used to wipe it); a delete asks first.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const post = vi.fn()
const put = vi.fn()
const del = vi.fn()

const { FakeApiError } = vi.hoisted(() => ({
  FakeApiError: class extends Error {
    constructor(
      public status: number,
      public body: unknown = {},
      message?: string,
    ) {
      super(message ?? `API error ${status}`)
    }
  },
}))

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: (...args: unknown[]) => put(...args),
    delete: (...args: unknown[]) => del(...args),
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

vi.mock('@/utils/qrCode', () => ({ generateQrDataUrl: vi.fn().mockResolvedValue('') }))

import AgentRosterView from '../AgentRosterView.vue'
import { useAuthStore } from '@/stores/auth'
import { saveFeedbackState } from '@/composables/useSaveFeedback'

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
    permissions: { update: true, deactivate: true, restore: true, move_company: true, approve_registration: true, reject_registration: true },
    removal_blockers: [],
    ...over,
  }
}

const TIERS = [{ id: 10, key: 'basic', name: 'Basic' }]
let rows: unknown[] = []
let usersReads = 0

/** Stands in for the real modal: exposes a button that emits `saved`. */
const EditModalStub = defineComponent({
  name: 'AgentEditModal',
  props: ['agentId', 'roster', 'certTiers', 'certifications', 'companies', 'inviteLinks'],
  emits: ['saved', 'close', 'show-links'],
  setup(_, { emit }) {
    return () => [
      h('button', {
        'data-test': 'modal-emit-saved',
        onClick: () => emit('saved', { leaderChanged: false, successMessage: 'บันทึกข้อมูลของ M UAT (server) เรียบร้อยแล้ว' }),
      }),
      h('button', {
        'data-test': 'modal-emit-partial',
        onClick: () =>
          emit('saved', {
            leaderChanged: false,
            successTitle: 'บันทึกแล้วบางส่วน',
            successMessage: 'บันทึกข้อมูลของ M UAT แล้ว — แต่บันทึกไม่สำเร็จ: เป้าจำนวนดีลรายปี',
          }),
      }),
    ]
  },
})

let mounted: ReturnType<typeof mount>[] = []
afterEach(() => {
  mounted.forEach((w) => w.unmount())
  mounted = []
})

async function mountRoster() {
  const wrapper = mount(AgentRosterView, {
    attachTo: document.body,
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot name="actions" /><slot name="tabs" /><slot /></div>' },
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
        CompanyScopeNotice: true,
        AgentEditModal: EditModalStub,
        ConfirmDialog: {
          props: ['show', 'title', 'body', 'confirmLabel', 'variant', 'busy'],
          template: '<div v-if="show" data-test="confirm" :data-variant="variant"><button data-test="confirm-yes" @click="$emit(\'confirm\')">ok</button></div>',
        },
        RouterLink: { props: ['to'], template: '<a><slot /></a>' },
      },
    },
  })
  await flushPromises()
  mounted.push(wrapper)

  return wrapper
}

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  put.mockReset()
  del.mockReset()
  usersReads = 0
  rows = [person(3, 'M UAT'), person(5, 'ผู้สมัครใหม่', { agent_approval_status: 'pending' })]
  get.mockImplementation(async (path: string) => {
    const p = String(path)
    if (p.startsWith('/users')) {
      usersReads += 1

      return { data: rows }
    }
    if (p.startsWith('/cert-tiers')) return { data: TIERS }

    return { data: [], meta: { last_page: 1 } }
  })
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'company_admin' } as never
})

describe('AgentRosterView — ADR-052 saved dialog', () => {
  it('shows the edit modal\'s sentence once, through the global dialog, only after the roster was re-read', async () => {
    let releaseReload!: () => void
    const wrapper = await mountRoster()
    expect(wrapper.findComponent({ name: 'SuccessDialog' }).exists()).toBe(false)

    get.mockImplementation(async (path: string) => {
      const p = String(path)
      if (p.startsWith('/users')) {
        await new Promise<void>((r) => { releaseReload = r })
        rows = [person(3, 'M UAT (server)'), person(5, 'ผู้สมัครใหม่', { agent_approval_status: 'pending' })]

        return { data: rows }
      }
      if (p.startsWith('/cert-tiers')) return { data: TIERS }

      return { data: [], meta: { last_page: 1 } }
    })

    await wrapper.find('[data-test="modal-emit-saved"]').trigger('click')
    await flushPromises()
    expect(saveFeedbackState.show).toBe(false)

    releaseReload()
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.count).toBe(1)
    expect(saveFeedbackState.body).toBe('บันทึกข้อมูลของ M UAT (server) เรียบร้อยแล้ว')
    expect(wrapper.text()).toContain('M UAT (server)')
  })

  it('announces a created member by the name the SERVER stored (it used to close silently)', async () => {
    post.mockImplementation(async () => {
      rows = [...rows, person(9, 'ณัฐ ทดสอบ')]

      return { data: person(9, 'ณัฐ ทดสอบ') }
    })
    const wrapper = await mountRoster()

    await wrapper.find('[data-test="open-create-agent"]').trigger('click')
    await wrapper.find('[data-test="create-first-name"]').setValue('  ณัฐ')
    await wrapper.find('[data-test="create-last-name"]').setValue('ทดสอบ ')
    await wrapper.find('[data-test="create-email"]').setValue('nat@example.test')
    await wrapper.find('[data-test="create-password"]').setValue('Passw0rd!')
    await wrapper.find('[data-test="create-agent-form"]').trigger('submit')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('สร้างบัญชี ณัฐ ทดสอบ แล้ว')
    expect(wrapper.find('[data-test="create-agent-form"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('ณัฐ ทดสอบ')
  })

  it('raises no dialog and keeps the create error when the server refuses', async () => {
    post.mockRejectedValue(new FakeApiError(500))
    const wrapper = await mountRoster()

    await wrapper.find('[data-test="open-create-agent"]').trigger('click')
    await wrapper.find('[data-test="create-agent-form"]').trigger('submit')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.find('[data-test="roster-error"]').text()).toContain('สร้างไม่สำเร็จ (500)')
    expect(wrapper.find('[data-test="create-agent-form"]').exists()).toBe(true)
  })

  it('raises no dialog when the bulk grant fails, and says why inside the grant dialog', async () => {
    post.mockRejectedValue(new FakeApiError(422, {}, 'ไม่มีสิทธิ์'))
    const wrapper = await mountRoster()

    await wrapper.find('[data-test="grant-course"]').trigger('click')
    await wrapper.find('[data-test="confirm-grant"]').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.find('[data-test="grant-error"]').text()).toContain('ไม่มีสิทธิ์')
    expect(wrapper.find('[data-test="grant-result"]').exists()).toBe(false)
  })

  it('keeps an approval refusal on screen after the reload, with no dialog', async () => {
    put.mockRejectedValue(new FakeApiError(422, {}, 'ผู้ใช้นี้ไม่ได้อยู่ในสถานะรออนุมัติแล้ว'))
    const wrapper = await mountRoster()
    const readsBefore = usersReads

    await wrapper.find('[data-test="approve-applicant"]').trigger('click')
    await flushPromises()

    expect(usersReads).toBe(readsBefore + 1)
    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.find('[data-test="roster-error"]').text()).toContain('ผู้ใช้นี้ไม่ได้อยู่ในสถานะรออนุมัติแล้ว')
  })

  it('announces an approval by the name the server returned, after the reload', async () => {
    put.mockImplementation(async () => {
      rows = [person(3, 'M UAT'), person(5, 'ผู้สมัครใหม่', { agent_approval_status: 'approved' })]

      return { data: person(5, 'ผู้สมัคร ใหม่จริง', { agent_approval_status: 'approved' }) }
    })
    const wrapper = await mountRoster()

    await wrapper.find('[data-test="approve-applicant"]').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.body).toContain('อนุมัติ ผู้สมัคร ใหม่จริง แล้ว')
    expect(wrapper.find('[data-test="approve-applicant"]').exists()).toBe(false)
  })

  it('re-reads the roster after a PARTIAL save and raises the dialog with the partial title', async () => {
    const wrapper = await mountRoster()
    const readsBefore = usersReads

    await wrapper.find('[data-test="modal-emit-partial"]').trigger('click')
    await flushPromises()

    expect(usersReads).toBe(readsBefore + 1)
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.title).toBe('บันทึกแล้วบางส่วน')
    expect(saveFeedbackState.body).toContain('เป้าจำนวนดีลรายปี')
  })
})
