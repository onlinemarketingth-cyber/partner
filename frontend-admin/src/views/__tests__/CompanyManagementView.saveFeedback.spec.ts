/**
 * ADR-052 — every write on จัดการบริษัท ends in the one global "saved" dialog,
 * raised only after the server answered and the list was re-read; a failure
 * raises nothing; and the plan-type <select> never keeps a choice the server
 * refused.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const put = vi.fn()
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
    put: (...args: unknown[]) => put(...args),
    post: (...args: unknown[]) => post(...args),
    delete: (...args: unknown[]) => del(...args),
    postForm: vi.fn(),
  },
  ApiError: ApiErrorStub,
}))

import CompanyManagementView from '../CompanyManagementView.vue'
import { saveFeedbackState } from '@/composables/useSaveFeedback'

const STUBS = {
  HeroHeader: { template: '<div><slot name="actions" /><slot /></div>' },
  EmptyState: true,
  Icon: true,
  LoadingSkeleton: true,
  PlatformScopeBadge: true,
  RouterLink: { template: '<a><slot /></a>' },
  ConfirmDialog: {
    props: ['show', 'title', 'body', 'variant', 'busy', 'confirmLabel', 'cancelLabel', 'confirmPhrase', 'size'],
    template: '<div v-if="show" data-test="confirm" :data-variant="variant"><p>{{ body }}</p><button data-test="confirm-yes" @click="$emit(\'confirm\')">ok</button></div>',
  },
}

function company(over: Record<string, unknown> = {}) {
  return {
    id: 3,
    name: 'Thai Life insurance',
    slug: 'thai-life-insurance',
    currency_code: 'THB',
    is_active: true,
    is_test: false,
    commission_plan_type: 'unilevel',
    user_count: 11,
    created_at: '2026-01-01T00:00:00Z',
    payment_promptpay_id: null,
    payment_bank_name: null,
    payment_bank_account_number: null,
    payment_bank_account_name: null,
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
  const wrapper = mount(CompanyManagementView as never, { global: { stubs: STUBS }, attachTo: document.body })
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  post.mockReset()
  del.mockReset()
  listed = [company()]
  get.mockImplementation(async (path: string) => {
    const p = String(path)
    if (p.startsWith('/currencies')) return { data: [], default: 'THB' }
    if (p.includes('/removal')) return { data: { mode: 'empty', blockers: [], contents: [{ key: 'users', count: 1 }] } }

    return { data: listed }
  })
})

describe('CompanyManagementView — ADR-052 saved dialog', () => {
  it('raises the dialog only after the edit resolved, quoting the SERVER\'s stored name, over the re-read row', async () => {
    const wrapper = await mountView()
    await wrapper.find('[data-test="edit-company"]').trigger('click')
    await wrapper.find('[data-test="edit-name"]').setValue('thai life typed')

    const pending = deferred<{ data: unknown }>()
    put.mockReturnValue(pending.promise)
    await wrapper.find('[data-test="save-edit"]').trigger('click')
    await flushPromises()
    expect(saveFeedbackState.show).toBe(false)

    const stored = company({ name: 'Thai Life Insurance (stored)' })
    listed = [stored]
    pending.resolve({ data: stored })
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('Thai Life Insurance (stored)')
    expect(saveFeedbackState.body).not.toContain('thai life typed')
    expect(wrapper.find('[data-test="company-row"]').text()).toContain('Thai Life Insurance (stored)')
    wrapper.unmount()
  })

  it('shows the error and no dialog when the edit is refused', async () => {
    put.mockRejectedValue(new ApiErrorStub('slug ถูกใช้แล้ว'))
    const wrapper = await mountView()
    await wrapper.find('[data-test="edit-company"]').trigger('click')
    await wrapper.find('[data-test="save-edit"]').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.text()).toContain('แก้ไขไม่สำเร็จ: slug ถูกใช้แล้ว')
    wrapper.unmount()
  })

  it('asks before closing a company, then words the dialog from the state the server stored', async () => {
    put.mockImplementation(async () => {
      listed = [company({ is_active: false })]

      return { data: company({ is_active: false }) }
    })
    const wrapper = await mountView()

    await wrapper.find('[data-test="toggle-active"]').trigger('click')
    expect(put).not.toHaveBeenCalled()
    await wrapper.find('[data-test="confirm-yes"]').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/companies/3', { is_active: false })
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('ปิดบริษัท "Thai Life insurance" แล้ว')
    expect(wrapper.find('[data-test="toggle-active"]').text()).toContain('เปิดบริษัทอีกครั้ง')
    wrapper.unmount()
  })

  it('asks (danger) before deleting, then raises the dialog after the list is re-read', async () => {
    del.mockImplementation(async () => {
      listed = []
    })
    const wrapper = await mountView()

    await wrapper.find('[data-test="delete-company"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="confirm"]').attributes('data-variant')).toBe('danger')
    expect(del).not.toHaveBeenCalled()

    await wrapper.find('[data-test="confirm-yes"]').trigger('click')
    await flushPromises()

    expect(del).toHaveBeenCalledWith('/companies/3/purge', { confirm_name: 'Thai Life insurance' })
    expect(saveFeedbackState.body).toContain('ลบบริษัท "Thai Life insurance" แล้ว')
    expect(wrapper.find('[data-test="company-row"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('snaps the plan-type select back to the stored value when the change is refused', async () => {
    put.mockRejectedValue(new ApiErrorStub('refused'))
    const wrapper = await mountView()

    const select = wrapper.find('[data-test="plan-type-select"]')
    await select.setValue('binary')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/commission-settings', { company_id: 3, commission_plan_type: 'binary' })
    expect(saveFeedbackState.show).toBe(false)
    expect((select.element as HTMLSelectElement).value).toBe('unilevel')
    expect(wrapper.text()).toContain('อัปเดตไม่สำเร็จ')
    wrapper.unmount()
  })

  it('announces a plan-type change with the plan the server stored', async () => {
    put.mockImplementation(async () => {
      listed = [company({ commission_plan_type: 'binary' })]

      return { data: { commission_plan_type: 'binary' } }
    })
    const wrapper = await mountView()

    const select = wrapper.find('[data-test="plan-type-select"]')
    await select.setValue('binary')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('Thai Life insurance')
    expect((select.element as HTMLSelectElement).value).toBe('binary')
    wrapper.unmount()
  })
})
