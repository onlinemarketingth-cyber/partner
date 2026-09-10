/**
 * 2026-09-10 (human: "Admin ที่ใช้บัตร voucher นั้นต้องใช้วิธี Key
 * ทำให้รหัสสั้นลงไม่เกิน 6 ตัวได้หรือไม่").
 *
 * This field is where the code is TYPED — one-handed, at a counter, off a
 * customer's phone. Everything here is about the two ways that goes wrong:
 * a correct code refused because of how it was typed, and a legacy code
 * refused because the screen tidied it when it should not have.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { formatVoucherCode, isShortVoucherCode, tidyVoucherCodeInput } from '@/utils/voucherCode'

const get = vi.fn()
const post = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
  ApiError: class extends Error {},
}))

import VoucherRedeemView from '../VoucherRedeemView.vue'

const VOUCHER = {
  code: 'AB1234',
  status: 'active',
  status_label: 'ใช้ได้',
  usage_quota: 1,
  used_count: 0,
  quota_remaining: 1,
  expires_at: null,
  order_number: 'ORD-LJ7WDALN',
  product_name: 'GENESENN 1-Year Vital Blueprint',
  client_name: 'สมชาย ใจดี',
}

async function mountView() {
  const wrapper = mount(VoucherRedeemView, {
    global: { stubs: { HeroHeader: { template: '<div />' }, Icon: true, ConfirmDialog: true } },
  })
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  get.mockResolvedValue({ data: VOUCHER })
})

describe('tidying what a person types', () => {
  it('accepts the code in the shape it is printed on the card', () => {
    expect(tidyVoucherCodeInput('ab1-234')).toBe('AB1234')
    expect(tidyVoucherCodeInput(' ab1 234 ')).toBe('AB1234')
  })

  it('maps the letters the alphabet deliberately does not contain', () => {
    /*
     * There is no I, L, O or U in a generated code — precisely so that a
     * person who reads "0" as "O" off a screen can type either and be right.
     */
    expect(tidyVoucherCodeInput('ob1234')).toBe('0B1234')
    expect(tidyVoucherCodeInput('abI234')).toBe('AB1234')
    expect(tidyVoucherCodeInput('abl234')).toBe('AB1234')
  })

  it('never lets a stray space become a digit', () => {
    // The mapping is per character. A substring check ('IL '.includes(c))
    // would quietly turn a space into '1' and corrupt a correct code.
    expect(tidyVoucherCodeInput('AB 234')).toBe('AB234')
  })

  it('leaves a pasted legacy code exactly as it was', () => {
    /*
     * THE ONE THAT MATTERS. A 40-character code from before the change is
     * case-sensitive and may legitimately contain O, I and l. Tidying it would
     * turn a valid card into "ไม่พบรหัสบัตรกำนัลนี้ในระบบ".
     */
    const legacy = 'aBcOlxyz'.repeat(5)

    expect(tidyVoucherCodeInput(legacy)).toBe(legacy)
    expect(isShortVoucherCode(legacy)).toBe(false)
  })

  it('shows a short code grouped and a long one untouched', () => {
    expect(formatVoucherCode('AB1234')).toBe('AB1-234')
    expect(formatVoucherCode('x'.repeat(40))).toBe('x'.repeat(40))
  })
})

describe('the redemption screen', () => {
  it('corrects the field as it is typed, so staff see what they are sending', async () => {
    const wrapper = await mountView()

    await wrapper.find('[data-test="code-input"]').setValue('ab1-234')

    expect((wrapper.find('[data-test="code-input"]').element as HTMLInputElement).value).toBe('AB1234')
  })

  it('looks the code up as typed and shows it the way the card prints it', async () => {
    const wrapper = await mountView()

    await wrapper.find('[data-test="code-input"]').setValue('ab1-234')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(get).toHaveBeenCalledWith('/vouchers/AB1234')
    expect(wrapper.find('[data-test="found-code"]').text()).toBe('AB1-234')
  })
})
