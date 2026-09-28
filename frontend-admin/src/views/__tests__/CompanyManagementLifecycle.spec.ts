/**
 * 2026-09-26 — closing, deleting, and the บริษัททดสอบ flag on จัดการบริษัท.
 *
 * Owner: "ทำทีเดียวหากบริษัทยังไม่มีการดำเนินการใด ให้ลบได้โดยสอบถามว่าจะลบ
 * ทั้งหมดหรือไม่ … เมื่อทดสอบเสร็จ จะลบได้ต่อเมื่อไม่มีข้อมูลผู้สมัคร ค่าคอม …
 * ทำปุ่มปิดบริษัท ที่ปิดการใช้งานทุกระบบในบริษัทนี้".
 *
 * The rules themselves are the server's (CompanyRemovalService, tested in
 * CompanyRemovalTest). What is pinned here is that the screen asks before it
 * acts, shows the server's answer rather than its own, and never offers a
 * delete the server has refused.
 *
 * ConfirmDialog is mounted for real, as in CompanyManagementEdit.spec.ts.
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
import { useActiveCompanyStore } from '@/stores/activeCompany'

const STUBS = {
  HeroHeader: { template: '<div><slot name="actions" /><slot /></div>' },
  EmptyState: true,
  Icon: true,
  LoadingSkeleton: true,
  PlatformScopeBadge: true,
  InfoPopover: true,
  RouterLink: { template: '<a><slot /></a>' },
}

const BASE = {
  id: 7,
  name: 'GENESENN',
  slug: 'genesenn',
  currency_code: 'THB',
  currency_symbol: '฿',
  is_active: true,
  is_test: false,
  went_live_at: null as string | null,
  commission_plan_type: 'unilevel' as const,
  user_count: 3,
  created_at: '2026-09-01T00:00:00Z',
  payment_promptpay_id: null,
  payment_bank_name: null,
  payment_bank_account_number: null,
  payment_bank_account_name: null,
}

type Removal = { mode: 'wipe' | 'empty' | 'blocked'; blockers: { key: string; count: number }[]; contents: { key: string; count: number }[] }

async function mountWith(company: typeof BASE, removal?: Removal) {
  get.mockImplementation((url: string) => {
    if (url.endsWith('/removal')) return Promise.resolve({ data: removal })
    if (url === '/currencies') return Promise.resolve({ data: [], default: 'THB' })

    return Promise.resolve({ data: [company] })
  })
  const wrapper = mount(CompanyManagementView as never, { global: { stubs: STUBS } })
  await flushPromises()
  vi.spyOn(useActiveCompanyStore(), 'reloadCompanies').mockResolvedValue()

  return wrapper
}

function dialog(wrapper: Awaited<ReturnType<typeof mountWith>>) {
  return wrapper.find('.fixed.inset-0')
}

/** [cancel, confirm] — the order ConfirmDialog renders them. */
function dialogButtons(wrapper: Awaited<ReturnType<typeof mountWith>>) {
  const d = dialog(wrapper)

  return d.exists() ? d.findAll('button') : []
}

beforeEach(() => {
  get.mockReset()
  put.mockReset().mockResolvedValue({ data: {} })
  post.mockReset().mockResolvedValue({ data: {} })
  del.mockReset().mockResolvedValue(undefined)
})

describe('ปิดบริษัท', () => {
  it('the status chip is a label now, not a one-click kill switch', async () => {
    const wrapper = await mountWith(BASE)
    const label = wrapper.find('[data-test="status-label"]')

    expect(label.element.tagName).toBe('SPAN')
    await label.trigger('click')
    await flushPromises()

    expect(put).not.toHaveBeenCalled()
    expect(dialogButtons(wrapper)).toHaveLength(0)
  })

  it('asks first, says what stops — money jobs included — then closes', async () => {
    const wrapper = await mountWith(BASE)

    await wrapper.find('[data-test="toggle-active"]').trigger('click')
    expect(put).not.toHaveBeenCalled()

    const body = dialog(wrapper).text()
    expect(body).toContain('เข้าสู่ระบบไม่ได้')
    expect(body).toContain('ค่าคอมต่ออายุ')
    expect(body).toContain('เปิดบริษัทอีกครั้งได้ทุกเมื่อ')

    await dialogButtons(wrapper)[1]?.trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/companies/7', { is_active: false })
  })

  it('a closed company offers to reopen it', async () => {
    const wrapper = await mountWith({ ...BASE, is_active: false })

    expect(wrapper.find('[data-test="status-label"]').text()).toBe('ปิดอยู่')
    await wrapper.find('[data-test="toggle-active"]').trigger('click')
    await dialogButtons(wrapper)[1]?.trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/companies/7', { is_active: true })
  })
})

describe('ลบบริษัท', () => {
  it('a test company: lists what goes and wants the name typed before deleting', async () => {
    const wrapper = await mountWith({ ...BASE, is_test: true }, {
      mode: 'wipe',
      blockers: [],
      contents: [{ key: 'users', count: 3 }, { key: 'commission', count: 12 }, { key: 'orders', count: 0 }],
    })

    await wrapper.find('[data-test="delete-company"]').trigger('click')
    await flushPromises()

    expect(get).toHaveBeenCalledWith('/companies/7/removal')
    const body = dialog(wrapper).text()
    expect(body).toContain('ผู้ใช้งานทั้งหมด 3 คน')
    expect(body).toContain('รายการค่าคอมมิชชัน 12 รายการ')
    expect(body).not.toContain('คำสั่งซื้อ 0') // zero rows are not news

    // Nothing typed: the confirm does nothing.
    await dialogButtons(wrapper)[1]?.trigger('click')
    await flushPromises()
    expect(del).not.toHaveBeenCalled()

    await wrapper.find('[data-test="confirm-phrase"]').setValue('GENESENN')
    await dialogButtons(wrapper)[1]?.trigger('click')
    await flushPromises()

    expect(del).toHaveBeenCalledWith('/companies/7/purge', { confirm_name: 'GENESENN' })
  })

  it('a real company with data: no delete at all, and the way out is ปิดบริษัท', async () => {
    const wrapper = await mountWith(BASE, {
      mode: 'blocked',
      blockers: [{ key: 'agents', count: 10 }, { key: 'commission', count: 4 }],
      contents: [],
    })

    await wrapper.find('[data-test="delete-company"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="confirm-phrase"]').exists()).toBe(false)
    const body = dialog(wrapper).text()
    expect(body).toContain('ลบไม่ได้')
    expect(body).toContain('ตัวแทน / ผู้สมัคร 10 คน')
    expect(dialogButtons(wrapper)[1]?.text()).toBe('ปิดบริษัทแทน')

    await dialogButtons(wrapper)[1]?.trigger('click')
    await flushPromises()

    expect(del).not.toHaveBeenCalled()
    // …and it lands on the close dialog, which still asks.
    expect(dialog(wrapper).text()).toContain('ทุกระบบของ "GENESENN" จะหยุด')
    expect(put).not.toHaveBeenCalled()
  })

  it('shows the server\'s refusal word for word', async () => {
    const wrapper = await mountWith({ ...BASE, is_test: true }, { mode: 'wipe', blockers: [], contents: [] })
    del.mockRejectedValue(new ApiErrorStub('ชื่อที่พิมพ์ไม่ตรงกับชื่อบริษัท — ยังไม่ได้ลบอะไร'))

    await wrapper.find('[data-test="delete-company"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="confirm-phrase"]').setValue('GENESENN')
    await dialogButtons(wrapper)[1]?.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('ยังไม่ได้ลบอะไร')
  })
})

describe('บริษัททดสอบ', () => {
  it('can be chosen when the company is created', async () => {
    const wrapper = await mountWith(BASE)

    await wrapper.find('[data-test="toggle-create"]').trigger('click')
    await wrapper.find('[data-test="create-name"]').setValue('UAT ใหม่')
    await wrapper.find('[data-test="create-is-test"]').setValue(true)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/companies', expect.objectContaining({ is_test: true }))
  })

  it('going live asks first and says it is one-way', async () => {
    const wrapper = await mountWith({ ...BASE, is_test: true })

    expect(wrapper.find('[data-test="test-chip"]').exists()).toBe(true)
    await wrapper.find('[data-test="edit-company"]').trigger('click')
    await wrapper.find('[data-test="go-live"]').trigger('click')

    expect(dialog(wrapper).text()).toContain('ย้อนกลับไม่ได้')
    expect(put).not.toHaveBeenCalled()

    await dialogButtons(wrapper)[1]?.trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/companies/7/test-mode', { is_test: false })
  })

  it('a company that has gone live has no way back to test', async () => {
    const wrapper = await mountWith({ ...BASE, went_live_at: '2026-09-20T00:00:00Z' })

    await wrapper.find('[data-test="edit-company"]').trigger('click')

    expect(wrapper.find('[data-test="mark-test"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="go-live"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="company-type"]').text()).toContain('ใช้งานจริง')
  })
})
