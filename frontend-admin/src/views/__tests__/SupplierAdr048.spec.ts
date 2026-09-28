/**
 * ADR-048 (2026-09-27) — the owner's answers about suppliers, on the admin
 * screens.
 *
 *   · no supplier self-withdrawal → the "minimum per request" field is gone,
 *     and a raised payout can be CANCELLED (a failed transfer used to leave
 *     the rows reserved forever);
 *   · refunds come off the next payout → refund rows are labelled as such;
 *   · receipt, not shipping, releases a deliver-before-pay deal → the trigger
 *     is named for receipt, the auto-receive window is a setting on
 *     จัดการคู่ค้า, and the supplier sees when the recipient confirmed.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const post = vi.fn()
const put = vi.fn()

const { ApiErrorStub } = vi.hoisted(() => ({
  ApiErrorStub: class extends Error {
    status = 422
  },
}))

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: (...args: unknown[]) => put(...args),
    postForm: vi.fn(),
  },
  ApiError: ApiErrorStub,
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '7' } }),
  RouterLink: { template: '<a><slot /></a>' },
}))

import SupplierPayoutsView from '../SupplierPayoutsView.vue'
import SupplierSettlementsView from '../SupplierSettlementsView.vue'
import SupplierOrdersView from '../SupplierOrdersView.vue'
import SupplierManagementView from '../SupplierManagementView.vue'
import SupplierForm from '../supplier/SupplierForm.vue'
import { RELEASE_TRIGGER_LABELS, emptySupplier } from '../supplier/supplierTerms'

const STUBS = {
  HeroHeader: { template: '<div><slot name="actions" /><slot /></div>' },
  EmptyState: true,
  Icon: true,
  LoadingSkeleton: true,
  InfoPopover: true,
  RouterLink: { template: '<a><slot /></a>' },
}

function mountView(component: unknown) {
  return mount(component as never, { global: { stubs: STUBS } })
}

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  put.mockReset()
})

describe('cancelling a raised payout', () => {
  const REQUEST = {
    id: 5,
    supplier_id: 7,
    supplier_name: 'บริษัท ซัพพลายเออร์ จำกัด',
    status: 'approved',
    source: 'company_payout',
    gross_satang: 100000,
    wht_rate_at_time: null,
    wht_satang: 0,
    net_satang: 100000,
    wht_certificate_no: null,
    bank_name: 'ธนาคารกสิกรไทย',
    bank_account_number: '123-4-56789-0',
    bank_account_holder_name: 'บริษัท ซัพพลายเออร์ จำกัด',
    transferred_at: null,
    transfer_reference: null,
    created_at: '2026-09-27T03:00:00Z',
  }

  function mockPayouts(requests: unknown[] = [REQUEST]) {
    get.mockImplementation(async (path: string) => (path === '/supplier-payouts' ? { data: [] } : { data: requests }))
  }

  it('asks for a reason and will not send without one', async () => {
    mockPayouts()
    const wrapper = mountView(SupplierPayoutsView)
    await flushPromises()

    await wrapper.find('[data-test="open-cancel-payout"]').trigger('click')
    expect(wrapper.find('[data-test="confirm-cancel-payout"]').attributes('disabled')).toBeDefined()

    await wrapper.find('[data-test="cancel-reason"]').setValue('โอนไม่สำเร็จ')
    post.mockResolvedValue({ data: {} })
    await wrapper.find('[data-test="confirm-cancel-payout"]').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/supplier-payouts/5/cancel', { reason: 'โอนไม่สำเร็จ' })
  })

  it('shows why a cancelled payout was cancelled in the history', async () => {
    mockPayouts([{ ...REQUEST, status: 'cancelled', cancel_reason: 'บัญชีปิดแล้ว' }])
    const wrapper = mountView(SupplierPayoutsView)
    await flushPromises()

    expect(wrapper.find('[data-test="history-table"]').text()).toContain('บัญชีปิดแล้ว')
  })
})

describe('refund rows', () => {
  it('are labelled on the supplier statement', async () => {
    const row = {
      id: 1,
      order_number: 'ORD-0001',
      product_name: 'สินค้า',
      sale_price_satang: 100000,
      released_at: '2026-09-27T03:00:00Z',
      payment_status: 'pending',
      created_at: '2026-09-27T03:00:00Z',
    }
    get.mockImplementation(async (path: string) => {
      if (path === '/supplier/balance') return { data: { payable_satang: 0, reserved_satang: 0, unreleased_satang: 0 } }
      if (path === '/supplier/settlements') {
        return { data: [
          { ...row, entry_kind: 'sale', amount_satang: 70000 },
          { ...row, id: 2, entry_kind: 'refund', amount_satang: -70000 },
        ] }
      }
      return { data: [] }
    })
    const wrapper = mountView(SupplierSettlementsView)
    await flushPromises()

    expect(wrapper.findAll('[data-test="refund-chip"]')).toHaveLength(1)
  })
})

describe('receipt, not shipping', () => {
  it('names the trigger for receipt', () => {
    expect(RELEASE_TRIGGER_LABELS.on_delivered).toBe('เมื่อผู้รับได้รับสินค้า')
  })

  it('shows the supplier when the recipient confirmed, and when it was the system', async () => {
    get.mockResolvedValue({ data: [{
      id: 3,
      order_number: 'ORD-3',
      status: 'paid',
      status_label: 'ชำระแล้ว',
      paid_at: '2026-09-01T03:00:00Z',
      created_at: '2026-09-01T03:00:00Z',
      product_name: 'สินค้า',
      sale_price_satang: 100000,
      customer_name: 'ลูกค้า',
      requires_shipping: true,
      shipping_status: 'delivered',
      shipping_status_label: 'ได้รับแล้ว',
      tracking_number: 'TH1',
      shipped_at: '2026-09-02T03:00:00Z',
      received_at: '2026-09-17T03:00:00Z',
      receipt_confirmed_via: 'auto',
      shipping_recipient_name: 'ลูกค้า',
      shipping_phone: '0812345678',
      shipping_address: 'กรุงเทพฯ',
    }] })
    const wrapper = mountView(SupplierOrdersView)
    await flushPromises()
    // The screen opens filtered to "รอจัดส่ง"; this order has left.
    const toggle = wrapper.find('[data-test="toggle-pending"]')
    if (toggle.exists()) {
      await toggle.trigger('click')
      await flushPromises()
    }

    expect(wrapper.find('[data-test="received-note"]').text()).toContain('ระบบยืนยันให้อัตโนมัติ')
  })

  it('loads and saves the auto-receive window on จัดการคู่ค้า', async () => {
    get.mockImplementation(async (path: string) =>
      path === '/supplier-settings' ? { data: { auto_receive_days: 15 } } : { data: [], meta: { total: 0 } },
    )
    put.mockResolvedValue({ data: { auto_receive_days: 10 } })
    const wrapper = mountView(SupplierManagementView)
    await flushPromises()

    const input = wrapper.find('[data-test="auto-receive-days"]')
    expect((input.element as HTMLInputElement).value).toBe('15')
    expect(wrapper.find('[data-test="save-auto-receive"]').attributes('disabled')).toBeDefined()

    await input.setValue('10')
    await wrapper.find('[data-test="save-auto-receive"]').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/supplier-settings', { auto_receive_days: 10 })
    expect(wrapper.find('[data-test="auto-receive-message"]').text()).toBe('บันทึกแล้ว')
  })
})

describe('no supplier self-withdrawal', () => {
  it('has no "minimum per withdrawal request" field any more', () => {
    const wrapper = mount(SupplierForm, { props: { modelValue: emptySupplier() }, global: { stubs: STUBS } })

    expect(wrapper.find('[data-test="min-withdrawal"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('ยอดขั้นต่ำต่อการขอเบิก')
  })
})
