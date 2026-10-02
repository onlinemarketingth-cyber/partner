/**
 * ADR-052 — "saved" must be true without F5, on the campaign label.
 *
 * saveLabel() used to give NO feedback at all and wrote the typed draft
 * straight onto the row (`link.label = labelDraft`), so what the agent saw
 * was their own typing whether or not the server agreed. These pin:
 *   - the success toast appears only after PUT resolves,
 *   - the row then shows the label the SERVER answered with, not the draft,
 *   - a rejected PUT gives no success toast, keeps the edit box open with
 *     the draft, and the stored label is still what the row shows.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()
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
    get: (...args: unknown[]) => get(...args),
    post: vi.fn(),
    put: (...args: unknown[]) => put(...args),
    patch: vi.fn(),
    delete: vi.fn(),
  },
  ApiError: FakeApiError,
}))

import MyLinksView from '../MyLinksView.vue'
import { useToastStore } from '@/stores/toast'

function linkFixture(overrides: Record<string, unknown> = {}) {
  return {
    id: 3,
    group: 'product_share',
    group_label: 'แชร์สินค้า',
    code: 'abc',
    short_url: 'https://s.test/abc',
    label: 'ชื่อเดิม',
    expires_at: null,
    revoked_at: null,
    is_usable: true,
    click_count: 4,
    unique_click_count: 2,
    conversion_count: 1,
    conversion_rate: 50,
    first_clicked_at: null,
    last_clicked_at: null,
    created_at: '2026-09-01T00:00:00Z',
    ...overrides,
  }
}

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((res, rej) => {
    resolve = res
    reject = rej
  })
  return { promise, resolve, reject }
}

async function mountAndEdit(typed: string) {
  const wrapper = mount(MyLinksView, { global: { stubs: { Icon: true, EmptyState: true, LoadingSkeleton: true } } })
  await flushPromises()

  const labelButton = wrapper.findAll('button').find((b) => b.text().includes('ชื่อเดิม'))
  if (!labelButton) throw new Error('no label button for the stored label')
  await labelButton.trigger('click')
  await wrapper.find('input[type="text"]').setValue(typed)

  return wrapper
}

function saveButton(wrapper: Awaited<ReturnType<typeof mountAndEdit>>) {
  const button = wrapper.findAll('button').find((b) => b.text() === 'บันทึก')
  if (!button) throw new Error('no save button in the label editor')
  return button
}

const successes = () => useToastStore().toasts.filter((t) => t.variant === 'success')

beforeEach(() => {
  setActivePinia(createPinia())
  get.mockReset()
  put.mockReset()
  get.mockResolvedValue({ data: [linkFixture()] })
})

describe('MyLinksView — campaign label save feedback (ADR-052)', () => {
  it('toasts only after PUT resolves, and shows the label the SERVER stored', async () => {
    const pending = deferred<unknown>()
    put.mockReturnValue(pending.promise)
    const wrapper = await mountAndEdit('  พิมพ์เอง  ')

    await saveButton(wrapper).trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/tracked-links/3', { label: 'พิมพ์เอง' })
    // In flight: nothing claimed yet.
    expect(successes()).toHaveLength(0)

    // The server normalises it to something else — that is what must show.
    pending.resolve({ data: linkFixture({ label: 'ชื่อจากเซิร์ฟเวอร์' }) })
    await flushPromises()

    expect(successes()).toHaveLength(1)
    expect(successes()[0]!.message).toContain('ชื่อจากเซิร์ฟเวอร์')
    expect(wrapper.text()).toContain('ชื่อจากเซิร์ฟเวอร์')
    expect(wrapper.text()).not.toContain('พิมพ์เอง')
    expect(wrapper.find('input[type="text"]').exists()).toBe(false)
  })

  it('on a rejected PUT: no success toast, an error toast, edit box stays open, stored label kept', async () => {
    put.mockRejectedValue(new FakeApiError(422, {}))
    const wrapper = await mountAndEdit('ชื่อใหม่')

    await saveButton(wrapper).trigger('click')
    await flushPromises()

    expect(successes()).toHaveLength(0)
    expect(useToastStore().toasts.some((t) => t.variant === 'error' && t.message.includes('บันทึกชื่อไม่สำเร็จ'))).toBe(true)
    // The draft is still there to retry…
    expect((wrapper.find('input[type="text"]').element as HTMLInputElement).value).toBe('ชื่อใหม่')

    // …and cancelling shows the label that is actually stored.
    const cancel = wrapper.findAll('button').find((b) => b.text() === 'ยกเลิก')
    await cancel!.trigger('click')
    expect(wrapper.text()).toContain('ชื่อเดิม')
    expect(wrapper.text()).not.toContain('ชื่อใหม่')
  })

  it('clearing the label says so, and the row falls back to the server\'s null', async () => {
    put.mockResolvedValue({ data: linkFixture({ label: null }) })
    const wrapper = await mountAndEdit('   ')

    await saveButton(wrapper).trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/tracked-links/3', { label: null })
    expect(successes().map((t) => t.message)).toEqual(['ลบชื่อแคมเปญแล้ว'])
    expect(wrapper.text()).toContain('ตั้งชื่อแคมเปญ')
  })
})
