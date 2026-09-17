/**
 * 2026-09-17 — editing a company from จัดการบริษัท.
 *
 * Owner: "การจัดการบริษัท เพิ่ม edit". Until this, a company could be created
 * and switched off and nothing in between — a name typed wrong stayed wrong
 * and the only fix was the database.
 *
 * ── THE ONE THAT MATTERS ──
 *
 * `slug` is not a label. It is the company's recruit and login link
 * (`/login?company=<slug>`) and its default invite code. Changing it
 * invalidates every link already handed out: a QR on a printed card, a
 * message in a LINE group, a bookmark. NOTHING ERRORS — people simply stop
 * being able to sign up, and the first anybody hears of it is a complaint.
 *
 * So the warning and the confirm are asserted here rather than trusted to
 * survive the next tidy-up of this screen. A silent slug edit is the kind of
 * damage that is expensive precisely because it is quiet.
 *
 * ── THE DIALOG IS THE REAL ONE ──
 *
 * These tests mount ConfirmDialog rather than stubbing it, and click its
 * actual buttons. That is deliberate: the first cut of this screen used
 * `window.confirm` and the owner sent back a screenshot of an unstyled OS box
 * titled "admin.partner.syncvision.io says". Stubbing the dialog would let
 * exactly that regression back in while every test here still passed — the
 * assertions would be about a component that is not on the screen.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const put = vi.fn()

const { ApiErrorStub } = vi.hoisted(() => ({
  ApiErrorStub: class extends Error {
    status = 422
  },
}))

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    put: (...args: unknown[]) => put(...args),
    post: vi.fn(),
    postForm: vi.fn(),
  },
  ApiError: ApiErrorStub,
}))

import CompanyManagementView from '../CompanyManagementView.vue'

const STUBS = {
  HeroHeader: { template: '<div><slot name="actions" /><slot /></div>' },
  EmptyState: true,
  Icon: true,
  LoadingSkeleton: true,
  PlatformScopeBadge: true,
  RouterLink: { template: '<a><slot /></a>' },
  // ConfirmDialog is NOT stubbed — see the file docblock.
}

const COMPANY = {
  id: 3,
  name: 'Thai Life insurance',
  slug: 'thai-life-insurance',
  is_active: true,
  commission_plan_type: 'unilevel' as const,
  user_count: 11,
  created_at: '2026-01-01T00:00:00Z',
  payment_promptpay_id: '0812345678',
  payment_bank_name: 'ธนาคารกสิกรไทย',
  payment_bank_account_number: '123-4-56789-0',
  payment_bank_account_name: 'บริษัท ไทยไลฟ์ จำกัด',
}

function mountView() {
  return mount(CompanyManagementView as never, { global: { stubs: STUBS } })
}

/** Open the edit panel on the one company in the list. */
async function openEditor() {
  get.mockResolvedValue({ data: [COMPANY] })
  const wrapper = mountView()
  await flushPromises()
  await wrapper.find('[data-test="edit-company"]').trigger('click')

  return wrapper
}

beforeEach(() => {
  get.mockReset()
  put.mockReset()
})

/** The dialog's own buttons, in the order it renders them: cancel, confirm. */
function dialogButtons(wrapper: ReturnType<typeof mountView>) {
  const dialog = wrapper.find('.fixed.inset-0')

  return dialog.exists() ? dialog.findAll('button') : []
}

describe('CompanyManagementView — แก้ไขบริษัท', () => {
  it('opens prefilled with what the company already is', async () => {
    // A form that opens empty is a form that blanks a field the moment
    // somebody saves after editing a different one.
    const wrapper = await openEditor()

    expect((wrapper.find('[data-test="edit-name"]').element as HTMLInputElement).value)
      .toBe('Thai Life insurance')
    expect((wrapper.find('[data-test="edit-slug"]').element as HTMLInputElement).value)
      .toBe('thai-life-insurance')
    expect((wrapper.find('[data-test="edit-promptpay"]').element as HTMLInputElement).value)
      .toBe('0812345678')
  })

  it('saves a rename with no dialog at all', async () => {
    // Renaming is safe and ordinary. A confirm on every save teaches people to
    // dismiss confirms, which is what would make the slug one worthless.
    const wrapper = await openEditor()
    put.mockResolvedValue({ data: COMPANY })

    await wrapper.find('[data-test="edit-name"]').setValue('Thai Life Insurance PCL')
    await wrapper.find('[data-test="save-edit"]').trigger('click')
    await flushPromises()

    expect(dialogButtons(wrapper)).toHaveLength(0)
    expect(put).toHaveBeenCalledWith('/companies/3', expect.objectContaining({
      name: 'Thai Life Insurance PCL',
      slug: 'thai-life-insurance',
    }))
  })

  it('warns inline the moment the slug is touched, before anything is saved', async () => {
    /*
     * Not after saving, and not in the dialog alone: somebody who reads the
     * warning while typing can still change their mind for free.
     */
    const wrapper = await openEditor()

    expect(wrapper.find('[data-test="slug-warning"]').exists()).toBe(false)

    await wrapper.find('[data-test="edit-slug"]').setValue('sws')

    expect(wrapper.find('[data-test="slug-warning"]').text()).toContain('ใช้ไม่ได้ทันที')
  })

  it('opens the in-app dialog rather than a browser alert', async () => {
    /*
     * THE REGRESSION THIS FILE EXISTS FOR, after the native one shipped.
     * `window.confirm` also BLOCKS the page and cannot show a busy state, so
     * a slow save leaves the screen looking frozen.
     */
    const confirmSpy = vi.spyOn(window, 'confirm')
    const wrapper = await openEditor()

    await wrapper.find('[data-test="edit-slug"]').setValue('sws')
    await wrapper.find('[data-test="save-edit"]').trigger('click')
    await flushPromises()

    expect(confirmSpy).not.toHaveBeenCalled()
    expect(dialogButtons(wrapper)).toHaveLength(2)

    confirmSpy.mockRestore()
  })

  it('does not save until the dialog is confirmed', async () => {
    const wrapper = await openEditor()
    put.mockResolvedValue({ data: COMPANY })

    await wrapper.find('[data-test="edit-slug"]').setValue('sws')
    await wrapper.find('[data-test="save-edit"]').trigger('click')
    await flushPromises()

    // Dialog up, nothing written yet.
    expect(put).not.toHaveBeenCalled()

    await dialogButtons(wrapper)[1]?.trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/companies/3', expect.objectContaining({ slug: 'sws' }))
  })

  it('writes nothing when the dialog is dismissed, and keeps the edit open', async () => {
    // "แก้ไขต่อ" has to mean what it says: the typed slug is still there to
    // correct, not thrown away along with the rest of the form.
    const wrapper = await openEditor()

    await wrapper.find('[data-test="edit-slug"]').setValue('sws')
    await wrapper.find('[data-test="save-edit"]').trigger('click')
    await flushPromises()

    await dialogButtons(wrapper)[0]?.trigger('click')
    await flushPromises()

    expect(put).not.toHaveBeenCalled()
    expect(dialogButtons(wrapper)).toHaveLength(0)
    expect(wrapper.find('[data-test="edit-panel"]').exists()).toBe(true)
    expect((wrapper.find('[data-test="edit-slug"]').element as HTMLInputElement).value).toBe('sws')
  })

  it('names both slugs and the consequences in the dialog', async () => {
    // "Are you sure?" is not a question anybody can answer. The old link, the
    // new one, and what breaks, is.
    const wrapper = await openEditor()

    await wrapper.find('[data-test="edit-slug"]').setValue('sws')
    await wrapper.find('[data-test="save-edit"]').trigger('click')
    await flushPromises()

    const body = wrapper.find('.fixed.inset-0').text()
    expect(body).toContain('thai-life-insurance')
    expect(body).toContain('sws')
    expect(body).toContain('รหัสเชิญ')
  })

  it('sends a cleared bank field as null rather than an empty string', async () => {
    /*
     * '' would be STORED, and the public payment page would then treat the
     * company as having a bank account configured and print a blank line under
     * it. Null means "not recorded" and the page omits the line.
     */
    const wrapper = await openEditor()
    put.mockResolvedValue({ data: COMPANY })

    await wrapper.find('[data-test="edit-promptpay"]').setValue('')
    await wrapper.find('[data-test="save-edit"]').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/companies/3', expect.objectContaining({
      payment_promptpay_id: null,
      payment_bank_name: 'ธนาคารกสิกรไทย',
    }))
  })

  it('never sends the commission plan from this form', async () => {
    /*
     * The plan has its own control on the row and its own endpoint behind its
     * own Ability (PUT /commission-settings). Including it here would be two
     * doors onto one column on one screen — exactly what moving the write in
     * 2026-09-12 was meant to end.
     */
    const wrapper = await openEditor()
    put.mockResolvedValue({ data: COMPANY })

    await wrapper.find('[data-test="edit-name"]').setValue('X')
    await wrapper.find('[data-test="save-edit"]').trigger('click')
    await flushPromises()

    expect(put.mock.calls[0]?.[1]).not.toHaveProperty('commission_plan_type')
  })

  it('shows the server refusal in full rather than a bare status code', async () => {
    // A duplicate slug is the failure that will actually happen here, and the
    // server's sentence names the field. "แก้ไขไม่สำเร็จ (422)" does not.
    const wrapper = await openEditor()
    put.mockRejectedValue(new ApiErrorStub('ลิงก์บริษัทนี้ถูกใช้ไปแล้ว'))

    await wrapper.find('[data-test="edit-slug"]').setValue('aia')
    await wrapper.find('[data-test="save-edit"]').trigger('click')
    await flushPromises()
    await dialogButtons(wrapper)[1]?.trigger('click')
    await flushPromises()

    // And the dialog is out of the way, rather than covering the message it
    // caused.
    expect(dialogButtons(wrapper)).toHaveLength(0)
    expect(wrapper.text()).toContain('ลิงก์บริษัทนี้ถูกใช้ไปแล้ว')
  })

  it('refuses to save an empty name or slug', async () => {
    // Both are `required` server-side; disabling the button says so before a
    // round trip rather than after one.
    const wrapper = await openEditor()

    await wrapper.find('[data-test="edit-name"]').setValue('   ')

    expect(wrapper.find('[data-test="save-edit"]').attributes('disabled')).toBeDefined()
  })

  it('closes the panel when the same row is clicked again', async () => {
    const wrapper = await openEditor()

    expect(wrapper.find('[data-test="edit-panel"]').exists()).toBe(true)

    await wrapper.find('[data-test="edit-company"]').trigger('click')

    expect(wrapper.find('[data-test="edit-panel"]').exists()).toBe(false)
  })

  it('still carries no supplier controls', async () => {
    /*
     * Guarded again here because this change ADDS a form to the screen the
     * owner rejected a supplier panel from, and "while we are editing the
     * company anyway" is exactly the thought that would put it back. A company
     * is a tenant we pay commission to; a supplier is a counterparty we buy
     * goods from. They are not two halves of one form.
     */
    const wrapper = await openEditor()

    expect(wrapper.text()).not.toContain('ตั้งค่าคู่ค้า')
    expect(wrapper.text()).not.toContain('GP')
  })
})
