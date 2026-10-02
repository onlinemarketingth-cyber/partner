/**
 * ADR-052 — every write on "จัดการผู้ใช้ระบบ" ends in the one global "saved"
 * dialog, only after the server answered AND the list was re-read, and a
 * failure never leaves a success on screen.
 *
 * The bug this file pins: ten writes shared one `successMessage` banner that
 * was never cleared, so an old "แก้ไขข้อมูลของ … แล้ว" sat beside the error of
 * a later write that had failed.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const put = vi.fn()
const post = vi.fn()
const del = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    put: (...args: unknown[]) => put(...args),
    post: (...args: unknown[]) => post(...args),
    delete: (...args: unknown[]) => del(...args),
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

import UserManagementView from '../UserManagementView.vue'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import { dismissSaved, saveFeedbackState } from '@/composables/useSaveFeedback'

const ALL = { update: true, deactivate: true, restore: true, move_company: true }

function makeUser(over: Record<string, unknown> = {}) {
  return {
    id: 7,
    name: 'สมชาย ใจดี',
    first_name: 'สมชาย',
    last_name: 'ใจดี',
    phone: null,
    email: 'somchai@example.com',
    role: 'company_admin',
    company: { id: 4, name: 'ไทยประกันชีวิต' },
    is_active: true,
    is_team_leader: false,
    last_login_at: '2026-09-04T02:00:00Z',
    created_at: '2026-01-01T00:00:00Z',
    permissions: { ...ALL },
    ...over,
  }
}

let listed: unknown[] = []

function deferred<T>() {
  let resolve!: (v: T) => void
  let reject!: (e: unknown) => void
  const promise = new Promise<T>((res, rej) => {
    resolve = res
    reject = rej
  })

  return { promise, resolve, reject }
}

async function mountView() {
  const wrapper = mount(UserManagementView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div />' },
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
        ConfirmDialog: {
          name: 'ConfirmDialog',
          props: ['show', 'title', 'body', 'confirmLabel', 'variant', 'confirmPhrase', 'busy', 'size'],
          template: '<div v-if="show" data-test="confirm" :data-variant="variant"><p>{{ body }}</p><button data-test="confirm-yes" @click="$emit(\'confirm\')">ok</button></div>',
        },
        RouterLink: { props: ['to'], template: '<a><slot /></a>' },
      },
    },
  })
  await flushPromises()

  return wrapper
}

const at = (wrapper: Awaited<ReturnType<typeof mountView>>, test: string) => wrapper.find(`[data-test="${test}"]`)

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  post.mockReset()
  del.mockReset()
  listed = [makeUser()]
  get.mockImplementation(async (path: string) => {
    if (String(path).startsWith('/companies')) return { data: [{ id: 4, name: 'ไทยประกันชีวิต', slug: 'tli' }] }

    return { data: listed }
  })
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
  useActiveCompanyStore().setCompany(null)
})

describe('UserManagementView — ADR-052 saved dialog', () => {
  it('raises the dialog only after the edit resolved, naming the SERVER\'s stored name, with the list re-read', async () => {
    const wrapper = await mountView()
    await at(wrapper, 'edit-user').trigger('click')
    await at(wrapper, 'edit-first-name').setValue('สมชาย ')

    const pending = deferred<{ data: unknown }>()
    put.mockReturnValue(pending.promise)
    await at(wrapper, 'submit-edit').trigger('click')
    await flushPromises()

    // In flight: nothing claims success yet.
    expect(saveFeedbackState.show).toBe(false)

    // The server normalised the name differently from what was typed.
    const stored = makeUser({ name: 'สมชาย ใจดีเสมอ', last_name: 'ใจดีเสมอ' })
    listed = [stored]
    pending.resolve({ data: stored })
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('สมชาย ใจดีเสมอ')
    // The row on screen is the re-read one.
    expect(wrapper.text()).toContain('สมชาย ใจดีเสมอ')
    expect(at(wrapper, 'success').exists()).toBe(false)
  })

  it('shows the error and no dialog when the edit is refused', async () => {
    put.mockRejectedValue(new Error('อีเมลนี้ถูกใช้แล้ว'))
    const wrapper = await mountView()
    await at(wrapper, 'edit-user').trigger('click')
    await at(wrapper, 'submit-edit').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(at(wrapper, 'edit-error').exists()).toBe(true)
  })

  it('never leaves an earlier success on screen next to a later error', async () => {
    put.mockResolvedValueOnce({ data: makeUser({ role: 'agent' }) })
    const wrapper = await mountView()

    await at(wrapper, 'demote').trigger('click')
    await at(wrapper, 'confirm-yes').trigger('click')
    await flushPromises()
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('สมาชิก')
    dismissSaved()

    put.mockRejectedValueOnce(new Error('เปลี่ยนไม่ได้'))
    await at(wrapper, 'demote').trigger('click')
    await at(wrapper, 'confirm-yes').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(at(wrapper, 'error').text()).toContain('เปลี่ยนบทบาทไม่สำเร็จ')
    // The old sticky banner is gone for good.
    expect(at(wrapper, 'success').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('เป็นสมาชิกแล้ว')
  })

  it('asks before closing an account, then raises the dialog once the list is re-read', async () => {
    del.mockResolvedValue(undefined)
    const wrapper = await mountView()

    await at(wrapper, 'deactivate').trigger('click')
    expect(del).not.toHaveBeenCalled()
    expect(at(wrapper, 'confirm').attributes('data-variant')).toBe('danger')
    expect(saveFeedbackState.show).toBe(false)

    const getsBefore = get.mock.calls.length
    await at(wrapper, 'confirm-yes').trigger('click')
    await flushPromises()

    expect(del).toHaveBeenCalledWith('/users/7')
    expect(get.mock.calls.length).toBeGreaterThan(getsBefore)
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('ปิดบัญชี สมชาย ใจดี แล้ว')
  })

  it('words the redemption-right dialog from what the server stored, not the checkbox', async () => {
    // The admin ticks the box; the server answers with no grant (e.g. refused
    // silently by policy). The dialog must follow the server.
    put.mockResolvedValue({ data: makeUser({ granted_abilities: [] }) })
    const wrapper = await mountView()

    await at(wrapper, 'edit-abilities').trigger('click')
    await at(wrapper, 'grant-voucher-redeem').setValue(true)
    await at(wrapper, 'submit-abilities').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/users/7/abilities', { abilities: ['voucher.redeem'] })
    expect(saveFeedbackState.body).toContain('ยกเลิกสิทธิ์ตัดบัตรกำนัลของ สมชาย ใจดี แล้ว')
  })
})
