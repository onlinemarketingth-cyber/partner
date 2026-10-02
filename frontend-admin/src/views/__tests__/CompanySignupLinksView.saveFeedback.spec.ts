/**
 * ADR-052 — ลิงก์สมัครของบริษัท: creating and revoking a signup link end in the
 * one global "saved" dialog, after the list has been re-read, naming what the
 * SERVER issued; a failure raises nothing and keeps its error visible.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const post = vi.fn()
const del = vi.fn()

const { ApiErrorStub } = vi.hoisted(() => ({
  ApiErrorStub: class extends Error {
    status = 422
  },
}))

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    delete: (...args: unknown[]) => del(...args),
    put: vi.fn(),
    patch: vi.fn(),
    postForm: vi.fn(),
  },
  ApiError: ApiErrorStub,
}))

import CompanySignupLinksView from '../CompanySignupLinksView.vue'
import { saveFeedbackState } from '@/composables/useSaveFeedback'
import { useAuthStore } from '@/stores/auth'

function link(over: Record<string, unknown> = {}) {
  return {
    id: 11,
    company_id: 4,
    code: 'thailife',
    label: 'บูธงาน',
    signup_url: 'https://example.test/c/thailife',
    expires_at: null,
    max_uses: null,
    used_count: 2,
    revoked_at: null,
    is_valid: true,
    created_at: '2026-09-01T00:00:00Z',
    ...over,
  }
}

let listed: unknown[] = []

function deferred<T>() {
  let resolve!: (v: T) => void
  const promise = new Promise<T>((res) => {
    resolve = res
  })

  return { promise, resolve }
}

async function mountView() {
  const wrapper = mount(CompanySignupLinksView, {
    props: { embedded: true },
    global: {
      stubs: {
        HeroHeader: true,
        CompanyScopeNotice: true,
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
        LinkQrModal: true,
        ConfirmDialog: {
          props: ['show', 'title', 'body', 'variant', 'busy'],
          template: '<div v-if="show" data-test="confirm" :data-variant="variant"><button data-test="confirm-yes" @click="$emit(\'confirm\')">ok</button></div>',
        },
      },
    },
  })
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  del.mockReset()
  listed = [link()]
  get.mockImplementation(async () => ({ data: listed }))
  useAuthStore().user = { id: 1, name: 'ผู้ดูแล', role: 'company_admin' } as never
})

describe('CompanySignupLinksView — ADR-052 saved dialog', () => {
  it('raises the dialog only after the create resolved and the new row is listed, naming the code the server issued', async () => {
    const wrapper = await mountView()
    await wrapper.find('[data-test="new-signup-link"]').trigger('click')
    await wrapper.find('[data-test="signup-code"]').setValue('Typed Code')

    const pending = deferred<{ data: unknown }>()
    post.mockReturnValue(pending.promise)
    await wrapper.find('[data-test="save-signup-link"]').trigger('click')
    await flushPromises()
    expect(saveFeedbackState.show).toBe(false)

    const issued = link({ id: 12, code: 'typed-code', label: null, signup_url: 'https://example.test/c/typed-code' })
    listed = [link(), issued]
    pending.resolve({ data: issued })
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('typed-code')
    expect(saveFeedbackState.body).not.toContain('Typed Code')
    // The new link (the data the admin needs) is on the list itself.
    expect(wrapper.text()).toContain('https://example.test/c/typed-code')
  })

  it('keeps the form error and raises no dialog when the create is refused', async () => {
    post.mockRejectedValue(new ApiErrorStub('รหัสนี้ถูกใช้แล้ว'))
    const wrapper = await mountView()
    await wrapper.find('[data-test="new-signup-link"]').trigger('click')
    await wrapper.find('[data-test="save-signup-link"]').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.text()).toContain('รหัสนี้ถูกใช้แล้ว')
  })

  it('asks (danger) before revoking, then raises the dialog once the list shows it revoked', async () => {
    del.mockImplementation(async () => {
      listed = [link({ revoked_at: '2026-10-01T00:00:00Z', is_valid: false })]
    })
    const wrapper = await mountView()

    await wrapper.find('[data-test="revoke-signup-link"]').trigger('click')
    expect(wrapper.find('[data-test="confirm"]').attributes('data-variant')).toBe('danger')
    expect(del).not.toHaveBeenCalled()

    await wrapper.find('[data-test="confirm-yes"]').trigger('click')
    await flushPromises()

    expect(del).toHaveBeenCalledWith('/company-invite-codes/11')
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('บูธงาน')
    expect(wrapper.find('[data-test="revoke-signup-link"]').exists()).toBe(false)
  })

  it('closes the confirm and shows the error, with no dialog, when the revoke is refused', async () => {
    del.mockRejectedValue(new ApiErrorStub('nope'))
    const wrapper = await mountView()

    await wrapper.find('[data-test="revoke-signup-link"]').trigger('click')
    await wrapper.find('[data-test="confirm-yes"]').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.find('[data-test="confirm"]').exists()).toBe(false)
    expect(wrapper.find('.bg-rose-50').exists()).toBe(true)
  })
})
