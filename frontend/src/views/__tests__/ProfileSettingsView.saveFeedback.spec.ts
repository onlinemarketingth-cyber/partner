/**
 * ADR-052 — on the profile page, "saved" must describe what the SERVER holds.
 *
 * The inputs are seeded once from auth.user at setup and nothing watches
 * them, so after a save they used to keep showing what was TYPED even
 * though the store had been updated from the response. A server that trims
 * or normalises (or keeps the previous value) was invisible until F5.
 *
 * Pinned for name, bank account and identity document:
 *   - the success toast appears only after PUT resolves,
 *   - the inputs then hold the RESPONSE's values, not the typed ones,
 *   - a rejected PUT gives no success toast and leaves the error inline.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const put = vi.fn()

const { FakeApiError } = vi.hoisted(() => ({
  FakeApiError: class extends Error {
    constructor(
      public status: number,
      public body: unknown,
    ) {
      super(`API error ${status}`)
    }
  },
}))

vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn().mockResolvedValue({ data: {} }),
    post: vi.fn(),
    put: (...args: unknown[]) => put(...args),
    patch: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: FakeApiError,
  ensureCsrfCookie: vi.fn().mockResolvedValue(undefined),
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  useRoute: () => ({ name: 'profile-settings', params: {}, query: {} }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import ProfileSettingsView from '../ProfileSettingsView.vue'
import { useAuthStore, type AuthUser } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'

const ME = {
  id: 7,
  name: 'สมหญิง ทดสอบ',
  first_name: 'สมหญิง',
  last_name: 'ทดสอบ',
  email: 'somying@example.com',
  role: 'agent',
  bank_name: 'กสิกรไทย',
  bank_account_number: '1112223334',
  bank_account_holder_name: 'สมหญิง ทดสอบ',
  national_id: null,
  id_document_type: null,
} as unknown as AuthUser

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((res) => {
    resolve = res
  })
  return { promise, resolve }
}

async function mountProfile() {
  useAuthStore().setUser(ME)
  const wrapper = mount(ProfileSettingsView, {
    global: { stubs: { Icon: true, HeroHeader: true, Teleport: true } },
  })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountProfile>>

function button(wrapper: Wrapper, label: string) {
  const found = wrapper.findAll('button').find((b) => b.text().trim() === label)
  if (!found) throw new Error(`no "${label}" button`)
  return found
}

/** Text inputs in template order: first, last, (passwords are not text), passport-or-segments…, bank ×3. */
function inputValue(wrapper: Wrapper, index: number) {
  return (wrapper.findAll('input[type="text"]')[index]!.element as HTMLInputElement).value
}

/** The change-password submit button (AppButton renders a <button>). */
function passwordButton(wrapper: Wrapper) {
  const matches = wrapper.findAll('button').filter((b) => b.text().trim() === 'เปลี่ยนรหัสผ่าน')
  const found = matches[matches.length - 1]
  if (!found) throw new Error('no change-password button')
  return found
}

const successes = () => useToastStore().toasts.filter((t) => t.variant === 'success')

beforeEach(() => {
  setActivePinia(createPinia())
  put.mockReset()
})

describe('ProfileSettingsView — saves show the server\'s values (ADR-052)', () => {
  it('saveName: toasts only after PUT resolves, then the inputs hold the SERVER\'s name', async () => {
    const pending = deferred<unknown>()
    put.mockReturnValue(pending.promise)
    const wrapper = await mountProfile()

    const [first, last] = wrapper.findAll('input[type="text"]')
    await first!.setValue('  สมหญิงพิมพ์  ')
    await last!.setValue('  นามสกุลพิมพ์  ')
    await button(wrapper, 'บันทึกชื่อ').trigger('click')
    await flushPromises()

    expect(successes()).toHaveLength(0)

    pending.resolve({
      data: { ...ME, first_name: 'สมหญิงเซิร์ฟเวอร์', last_name: 'นามสกุลเซิร์ฟเวอร์', name: 'สมหญิงเซิร์ฟเวอร์ นามสกุลเซิร์ฟเวอร์' },
    })
    await flushPromises()

    expect(successes()).toHaveLength(1)
    expect(successes()[0]!.message).toContain('สมหญิงเซิร์ฟเวอร์ นามสกุลเซิร์ฟเวอร์')
    expect(inputValue(wrapper, 0)).toBe('สมหญิงเซิร์ฟเวอร์')
    expect(inputValue(wrapper, 1)).toBe('นามสกุลเซิร์ฟเวอร์')
  })

  it('saveName rejected: no success toast, inline error, store still holds the stored name', async () => {
    put.mockRejectedValue(new FakeApiError(422, { errors: { first_name: ['required'] } }))
    const wrapper = await mountProfile()

    await wrapper.findAll('input[type="text"]')[0]!.setValue('ชื่อใหม่')
    await button(wrapper, 'บันทึกชื่อ').trigger('click')
    await flushPromises()

    expect(successes()).toHaveLength(0)
    expect(wrapper.text()).toContain('บันทึกไม่สำเร็จ')
    expect(useAuthStore().user?.first_name).toBe('สมหญิง')
  })

  it('saveBankAccount: inputs re-sync from the response (normalised number), toast after 2xx', async () => {
    const pending = deferred<unknown>()
    put.mockReturnValue(pending.promise)
    const wrapper = await mountProfile()

    const inputs = wrapper.findAll('input[type="text"]')
    const bankInputs = inputs.slice(-3)
    await bankInputs[0]!.setValue('  ไทยพาณิชย์  ')
    await bankInputs[1]!.setValue('123-4-56789-0')
    await bankInputs[2]!.setValue('ชื่อบัญชีพิมพ์')
    await button(wrapper, 'บันทึกบัญชีธนาคาร').trigger('click')
    await flushPromises()

    expect(successes()).toHaveLength(0)

    pending.resolve({
      data: {
        ...ME,
        bank_name: 'ไทยพาณิชย์',
        bank_account_number: '1234567890',
        bank_account_holder_name: 'ชื่อบัญชีจากเซิร์ฟเวอร์',
      },
    })
    await flushPromises()

    expect(successes().map((t) => t.message)).toEqual(['บันทึกบัญชีธนาคารแล้ว'])
    const after = wrapper.findAll('input[type="text"]').slice(-3)
    expect(after.map((i) => (i.element as HTMLInputElement).value)).toEqual([
      'ไทยพาณิชย์',
      '1234567890',
      'ชื่อบัญชีจากเซิร์ฟเวอร์',
    ])
  })

  it('saveBankAccount rejected: no success toast, inline error, stored number unchanged in the store', async () => {
    put.mockRejectedValue(new FakeApiError(422, {}))
    const wrapper = await mountProfile()

    await wrapper.findAll('input[type="text"]').slice(-3)[1]!.setValue('999')
    await button(wrapper, 'บันทึกบัญชีธนาคาร').trigger('click')
    await flushPromises()

    expect(successes()).toHaveLength(0)
    expect(wrapper.text()).toContain('บันทึกไม่สำเร็จ — ตรวจสอบข้อมูลที่กรอก')
    expect(useAuthStore().user?.bank_account_number).toBe('1112223334')
  })

  it('saveIdDocument: passport field re-syncs from the response, toast after 2xx', async () => {
    const pending = deferred<unknown>()
    put.mockReturnValue(pending.promise)
    const wrapper = await mountProfile()

    const passport = wrapper.findAll('button').find((b) => b.text().includes('หนังสือเดินทาง'))
    await passport!.trigger('click')
    await wrapper.find('input#profile_national_id').setValue('ab1234567')
    await button(wrapper, 'บันทึกเอกสารยืนยันตัวตน').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/me/id-document', { id_document_type: 'passport', national_id: 'AB1234567' })
    expect(successes()).toHaveLength(0)

    pending.resolve({ data: { ...ME, id_document_type: 'passport', national_id: 'AB7654321' } })
    await flushPromises()

    expect(successes().map((t) => t.message)).toEqual(['บันทึกเอกสารยืนยันตัวตนแล้ว'])
    expect((wrapper.find('input#profile_national_id').element as HTMLInputElement).value).toBe('AB7654321')
  })

  it('saveIdDocument rejected: no success toast, the server\'s message inline', async () => {
    put.mockRejectedValue(new FakeApiError(422, { errors: { national_id: ['เลขนี้ถูกใช้แล้วในบริษัท'] } }))
    const wrapper = await mountProfile()

    const passport = wrapper.findAll('button').find((b) => b.text().includes('หนังสือเดินทาง'))
    await passport!.trigger('click')
    await wrapper.find('input#profile_national_id').setValue('AB1234567')
    await button(wrapper, 'บันทึกเอกสารยืนยันตัวตน').trigger('click')
    await flushPromises()

    expect(successes()).toHaveLength(0)
    expect(wrapper.text()).toContain('เลขนี้ถูกใช้แล้วในบริษัท')
    expect(useAuthStore().user?.national_id).toBeNull()
  })

  it('savePassword: toast only after PUT resolves, fields cleared; rejected → no toast', async () => {
    const pending = deferred<unknown>()
    put.mockReturnValue(pending.promise)
    const wrapper = await mountProfile()

    const pw = wrapper.findAll('input[type="password"]')
    await pw[0]!.setValue('old-secret')
    await pw[1]!.setValue('new-secret-1')
    await pw[2]!.setValue('new-secret-1')
    await passwordButton(wrapper).trigger('click')
    await flushPromises()
    expect(successes()).toHaveLength(0)

    pending.resolve(undefined)
    await flushPromises()
    expect(successes().map((t) => t.message)).toEqual(['เปลี่ยนรหัสผ่านแล้ว'])
    expect(wrapper.findAll('input[type="password"]').map((i) => (i.element as HTMLInputElement).value)).toEqual(['', '', ''])

    put.mockRejectedValueOnce(new FakeApiError(422, { errors: { current_password: ['รหัสผ่านเดิมไม่ถูกต้อง'] } }))
    await wrapper.findAll('input[type="password"]')[0]!.setValue('wrong')
    await passwordButton(wrapper).trigger('click')
    await flushPromises()
    expect(successes()).toHaveLength(1)
    expect(wrapper.text()).toContain('รหัสผ่านเดิมไม่ถูกต้อง')
  })
})
