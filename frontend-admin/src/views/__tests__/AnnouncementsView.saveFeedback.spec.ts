/**
 * ADR-052 — AnnouncementsView: every write raises the "saved" dialog only
 * after the server answered and the screen shows what it stored.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { SAVED_BUT_STALE_BODY, saveFeedbackState } from '@/composables/useSaveFeedback'

const get = vi.fn()
const post = vi.fn()
const put = vi.fn()
const del = vi.fn()
const postForm = vi.fn()

const { ApiErrorStub } = vi.hoisted(() => ({
  ApiErrorStub: class extends Error {
    status = 422
  },
}))

vi.mock('@/api/client', () => ({
  api: {
    get: (...a: unknown[]) => get(...a),
    post: (...a: unknown[]) => post(...a),
    put: (...a: unknown[]) => put(...a),
    delete: (...a: unknown[]) => del(...a),
    postForm: (...a: unknown[]) => postForm(...a),
  },
  ApiError: ApiErrorStub,
}))

import AnnouncementsView from '../AnnouncementsView.vue'

const STUBS = {
  HeroHeader: { template: '<div><slot /><slot name="actions" /></div>' },
  EmptyState: true,
  Icon: true,
  LoadingSkeleton: true,
  CompanyScopeNotice: true,
  BuddhistDateInput: true,
  RichTextEditor: {
    props: ['modelValue'],
    emits: ['update:modelValue'],
    template: '<textarea data-test="rte" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
  },
  ConfirmDialog: {
    props: ['show', 'body'],
    emits: ['confirm', 'update:show'],
    template: '<div v-if="show" data-test="confirm"><span>{{ body }}</span><button data-test="confirm-yes" @click="$emit(\'confirm\')">ok</button></div>',
  },
}

function item(over: Record<string, unknown> = {}) {
  return {
    id: 1,
    company_id: 1,
    title: 'ประกาศเดิม',
    content: '<p>เนื้อหา</p>',
    audience: 'all_agents',
    target_cert_tier_id: null,
    target_cert_tier_name: null,
    target_cert_tier_mode: 'exact',
    is_pinned: false,
    show_as_modal: true,
    show_as_banner: false,
    banner_pages: null,
    published_at: null,
    expires_at: null,
    created_by: 1,
    created_by_name: null,
    created_at: '2026-09-01T00:00:00Z',
    image_url: null,
    video: null,
    ...over,
  }
}

let list: ReturnType<typeof item>[] = []

beforeEach(() => {
  vi.clearAllMocks()
  list = [item()]
  get.mockImplementation((url: string) => {
    if (url.startsWith('/announcements')) return Promise.resolve({ data: list })
    if (url.startsWith('/announcement-settings')) return Promise.resolve({ data: { repeat_count: 4, display_style: 'bottom_sheet' } })
    if (url.startsWith('/cert-tiers')) return Promise.resolve({ data: [] })
    if (url.startsWith('/video-processing-settings')) return Promise.resolve({ data: { max_upload_mb: 100 } })

    return Promise.resolve({ data: [] })
  })
})

async function mountView() {
  const wrapper = mount(AnnouncementsView, { global: { stubs: STUBS } })
  await flushPromises()

  return wrapper
}

describe('AnnouncementsView — ADR-052 save feedback', () => {
  it('banner settings: dialog only after the PUT resolved, and the form shows the SERVER value', async () => {
    let resolvePut: (v: unknown) => void = () => {}
    put.mockImplementation(() => new Promise((r) => (resolvePut = r)))
    const wrapper = await mountView()

    await wrapper.find('button[title="ตั้งค่า Banner ข่าวสาร"]').trigger('click')
    await flushPromises()
    const input = wrapper.find('input[type="number"]')
    await input.setValue(7)
    const saveBtn = wrapper.findAll('button').find((b) => b.text() === 'บันทึก')!
    await saveBtn.trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)

    resolvePut({ data: { repeat_count: 9, display_style: 'full_screen' } })
    await flushPromises()

    expect((wrapper.find('input[type="number"]').element as HTMLInputElement).value).toBe('9')
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('9')
    // the inline 2-second flash is gone — the dialog replaces it
    expect(wrapper.text()).not.toContain('บันทึกแล้ว')
  })

  it('banner settings: a refused save raises no dialog and shows the error', async () => {
    put.mockRejectedValue(new ApiErrorStub('ค่าไม่ถูกต้อง'))
    const wrapper = await mountView()

    await wrapper.find('button[title="ตั้งค่า Banner ข่าวสาร"]').trigger('click')
    await flushPromises()
    await wrapper.findAll('button').find((b) => b.text() === 'บันทึก')!.trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.text()).toContain('ค่าไม่ถูกต้อง')
  })

  it('create: the dialog quotes the STORED title and the list is re-read before it appears', async () => {
    post.mockImplementation(() => {
      list = [item({ id: 2, title: 'หัวข้อที่เซิร์ฟเวอร์เก็บ' }), item()]

      return Promise.resolve({ data: item({ id: 2, title: 'หัวข้อที่เซิร์ฟเวอร์เก็บ' }) })
    })
    const wrapper = await mountView()

    await wrapper.findAll('button').find((b) => b.text().includes('+ สร้างประกาศ'))!.trigger('click')
    await flushPromises()
    await wrapper.find('form input[required]').setValue('หัวข้อที่พิมพ์')
    await wrapper.find('[data-test="rte"]').setValue('<p>x</p>')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/announcements', expect.objectContaining({ title: 'หัวข้อที่พิมพ์' }))
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('หัวข้อที่เซิร์ฟเวอร์เก็บ')
    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.text()).toContain('หัวข้อที่เซิร์ฟเวอร์เก็บ')
  })

  it('delete: asks first, then deletes, re-reads the list and raises the dialog', async () => {
    del.mockImplementation(() => {
      list = []

      return Promise.resolve(undefined)
    })
    const wrapper = await mountView()

    await wrapper.findAll('button').find((b) => b.text().includes('ลบ'))!.trigger('click')
    expect(wrapper.find('[data-test="confirm"]').exists()).toBe(true)
    expect(del).not.toHaveBeenCalled()
    expect(saveFeedbackState.show).toBe(false)

    await wrapper.find('[data-test="confirm-yes"]').trigger('click')
    await flushPromises()

    expect(del).toHaveBeenCalledWith('/announcements/1')
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('ลบประกาศ')
    expect(wrapper.text()).not.toContain('ประกาศเดิม')
  })

  it('delete: a refused delete raises no dialog and keeps the row', async () => {
    del.mockRejectedValue(new ApiErrorStub('ลบไม่ได้'))
    const wrapper = await mountView()

    await wrapper.findAll('button').find((b) => b.text().includes('ลบ'))!.trigger('click')
    await wrapper.find('[data-test="confirm-yes"]').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.text()).toContain('ลบไม่ได้')
    expect(wrapper.text()).toContain('ประกาศเดิม')
  })
})

describe('AnnouncementsView — ADR-052 failed re-read', () => {
  it('delete landed but the list could not be re-read: the dialog says the screen may be stale', async () => {
    del.mockResolvedValue(undefined)
    const wrapper = await mountView()
    get.mockImplementation((url: string) =>
      url.startsWith('/announcements') ? Promise.reject(new ApiErrorStub('down')) : Promise.resolve({ data: [] }),
    )

    await wrapper.findAll('button').find((b) => b.text().includes('ลบ'))!.trigger('click')
    await wrapper.find('[data-test="confirm-yes"]').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe(SAVED_BUT_STALE_BODY)
    expect(wrapper.text()).toContain('down')
  })
})
