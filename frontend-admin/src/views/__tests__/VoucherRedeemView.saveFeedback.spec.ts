/**
 * ADR-052 — redeeming a voucher raises the "saved" dialog only after the
 * server answered and the card shows the SERVER's voucher (its used count).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { saveFeedbackState } from '@/composables/useSaveFeedback'

const get = vi.fn()
const post = vi.fn()

const { ApiErrorStub } = vi.hoisted(() => ({
  ApiErrorStub: class extends Error {
    status = 422
  },
}))

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
  },
  ApiError: ApiErrorStub,
}))

import VoucherRedeemView from '../VoucherRedeemView.vue'

const VOUCHER = {
  code: 'AB1234',
  status: 'active',
  status_label: 'ใช้ได้',
  usage_quota: 3,
  used_count: 0,
  quota_remaining: 3,
  expires_at: null,
  order_number: 'ORD-LJ7WDALN',
  product_name: 'GENESENN 1-Year Vital Blueprint',
  client_name: 'สมชาย ใจดี',
}

const ConfirmDialogStub = {
  props: ['show', 'body'],
  emits: ['confirm', 'cancel', 'update:show'],
  template: '<div v-if="show" data-test="confirm"><button data-test="confirm-yes" @click="$emit(\'confirm\')">ok</button></div>',
}

async function mountFound() {
  const wrapper = mount(VoucherRedeemView, {
    global: { stubs: { HeroHeader: { template: '<div />' }, Icon: true, ConfirmDialog: ConfirmDialogStub } },
  })
  await wrapper.get('[data-test="code-input"]').setValue('AB1234')
  await wrapper.get('form').trigger('submit')
  await flushPromises()

  return wrapper
}

async function redeem(wrapper: ReturnType<typeof mount>) {
  await wrapper.findAll('button').find((b) => b.text() === 'ตัดสิทธิ์')!.trigger('click')
}

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  get.mockResolvedValue({ data: VOUCHER })
})

describe('VoucherRedeemView — ADR-052 save feedback', () => {
  it('redeem: asks first, then the dialog quotes the SERVER’s used count after the card was updated', async () => {
    const wrapper = await mountFound()
    let resolvePost: (v: unknown) => void = () => {}
    post.mockImplementation(() => new Promise((r) => (resolvePost = r)))

    await redeem(wrapper)
    expect(wrapper.find('[data-test="confirm"]').exists()).toBe(true)
    expect(post).not.toHaveBeenCalled()

    await wrapper.get('[data-test="confirm-yes"]').trigger('click')
    await flushPromises()
    expect(saveFeedbackState.show).toBe(false)

    // The server says this was the SECOND use (someone redeemed it at another branch meanwhile).
    resolvePost({ data: { ...VOUCHER, used_count: 2, quota_remaining: 1 } })
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('ใช้ไปแล้ว 2 / 3 ครั้ง')
    expect(saveFeedbackState.body).toContain('AB1-234')
    // the card reads the server's voucher; the inline result keeps the details
    expect(wrapper.text()).toContain('2 / 3 (เหลือ 1)')
    expect(wrapper.text()).toContain('ตัดสิทธิ์สำเร็จ — ใช้ไปแล้ว 2 ครั้ง')
  })

  it('redeem: a refused redemption raises no dialog and shows the server’s reason', async () => {
    const wrapper = await mountFound()
    post.mockRejectedValue(new ApiErrorStub('บัตรกำนัลนี้ใช้ครบแล้ว'))

    await redeem(wrapper)
    await wrapper.get('[data-test="confirm-yes"]').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.text()).toContain('บัตรกำนัลนี้ใช้ครบแล้ว')
    expect(wrapper.text()).not.toContain('ตัดสิทธิ์สำเร็จ')
  })

  it('redeem: an exhausted voucher returned by the server hides the redeem button', async () => {
    const wrapper = await mountFound()
    post.mockResolvedValue({ data: { ...VOUCHER, status: 'exhausted', status_label: 'ใช้ครบแล้ว', used_count: 3, quota_remaining: 0 } })

    await redeem(wrapper)
    await wrapper.get('[data-test="confirm-yes"]').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(wrapper.text()).toContain('ใช้ครบแล้ว')
    expect(wrapper.findAll('button').find((b) => b.text() === 'ตัดสิทธิ์')).toBeUndefined()
  })
})
