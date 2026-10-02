/**
 * ADR-052 — "ตั้งค่าระบบ" (theme / brand): every write puts the server's
 * answer on screen, then says it saved. An asset upload takes ONLY the asset
 * from its response, so unsaved edits elsewhere on the page survive it.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const put = vi.fn()
const post = vi.fn()
const del = vi.fn()
const postForm = vi.fn()

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
    put: (...args: unknown[]) => put(...args),
    post: (...args: unknown[]) => post(...args),
    postForm: (...args: unknown[]) => postForm(...args),
    patch: vi.fn(),
    delete: (...args: unknown[]) => del(...args),
  },
  ApiError: FakeApiError,
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ user: { role: 'company_admin' } }),
}))

vi.mock('@/utils/qrCode', () => ({ generateQrDataUrl: vi.fn().mockResolvedValue('') }))
vi.mock('@/utils/imageCompression', () => ({ compressImage: vi.fn(async (f: File) => f) }))

import ThemeSettingsView from '../ThemeSettingsView.vue'
import { saveFeedbackState } from '@/composables/useSaveFeedback'

/** `noUncheckedIndexedAccess` — fail loudly when an expected element is missing. */
function nth<T>(items: T[], index: number): T {
  const item = items[index < 0 ? items.length + index : index]
  if (item === undefined) throw new Error(`no element at index ${index}`)

  return item
}

const THEME = {
  company: { name: 'Thai Life', slug: 'thai-life' },
  login_link: 'https://agent.example/login?company=thai-life',
  primary_hex: '#1e3a8a',
  accent_hex: '#f59e0b',
  nav_bg_hex: null,
  nav_bg_type: 'solid',
  nav_bg_config: null,
  nav_text_hex: null,
  nav_active_hex: null,
  card_bg_hex: null,
  card_text_hex: null,
  card_border_hex: null,
  card_shadow: null,
  background: { type: null, config: null, image_url: null },
  font_family: null,
  font_family_thai: null,
  font_family_latin: null,
  font_weights: null,
  logos: { nav_url: null, login_url: null, favicon_url: null, loading_url: null },
  loading: { bg_hex: null, message: null },
  label_overrides: {},
  nav_icon_overrides: {},
  recommended_slot_count: 8,
}

function preset(id: number, name: string) {
  return {
    id,
    name,
    is_system: false,
    is_shared: false,
    is_default_for_new_companies: false,
    primary_hex: '#000000',
    accent_hex: '#111111',
    nav_bg_hex: null,
    nav_bg_type: 'solid',
    nav_bg_config: null,
    card_bg_hex: null,
    background: { type: null, config: null, image_url: null },
    created_at: '2026-09-07T00:00:00Z',
  }
}

let presetRows: unknown[] = []

async function mountView() {
  get.mockImplementation((path: string) => {
    if (path === '/me/theme' || path.startsWith('/company-theme')) return Promise.resolve({ data: structuredClone(THEME) })
    if (path.startsWith('/theme-presets')) return Promise.resolve({ data: presetRows })
    if (path.startsWith('/companies')) return Promise.resolve({ data: [] })

    return Promise.reject(new FakeApiError(404, null))
  })
  const wrapper = mount(ThemeSettingsView, { global: { stubs: { Icon: true } } })
  await flushPromises()

  return wrapper
}
type Wrapper = Awaited<ReturnType<typeof mountView>>

function primaryHexBox(wrapper: Wrapper) {
  const section = wrapper.findAll('section').find((s) => s.find('h2').exists() && s.find('h2').text() === 'สี')!

  return section.findAll('input[type="text"]')[0]!
}
const value = (el: { element: Element }) => (el.element as HTMLInputElement).value
const buttonWith = (w: Wrapper, text: string) => w.findAll('button').find((b) => b.text().includes(text))!

beforeEach(() => {
  ;[get, put, post, del, postForm].forEach((m) => m.mockReset())
  presetRows = []
})

describe('ThemeSettingsView — save feedback (ADR-052)', () => {
  it('the theme save shows the modal only after the PUT resolved, with the server-stored colour in the form', async () => {
    const wrapper = await mountView()
    await primaryHexBox(wrapper).setValue('#123456')

    let resolvePut: (v: unknown) => void = () => {}
    put.mockReturnValue(new Promise((r) => (resolvePut = r)))
    await buttonWith(wrapper, 'บันทึกธีม/แบรนด์').trigger('click')
    await flushPromises()
    expect(saveFeedbackState.show).toBe(false)

    resolvePut({ data: { ...structuredClone(THEME), primary_hex: '#654321' } })
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('บันทึกการตั้งค่าธีมแล้ว')
    expect(value(primaryHexBox(wrapper))).toBe('#654321')
    expect(wrapper.text()).not.toContain('บันทึกแล้ว')
  })

  it('a failed theme save raises no modal and shows the error banner', async () => {
    const wrapper = await mountView()
    put.mockRejectedValue(new FakeApiError(422, null))

    await buttonWith(wrapper, 'บันทึกธีม/แบรนด์').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.text()).toContain('API error 422')
  })

  it('uploading a logo keeps every unsaved edit on the page and shows the uploaded logo, then the modal', async () => {
    const wrapper = await mountView()
    await primaryHexBox(wrapper).setValue('#abcdef')
    // The server's copy of everything else is the OLD theme — applying it
    // wholesale (the old populateForm) would have reverted the edit above.
    postForm.mockResolvedValue({
      data: { ...structuredClone(THEME), logos: { ...THEME.logos, nav_url: 'https://cdn.test/nav.png' } },
    })

    const input = wrapper.findAll('input[type="file"][accept*="svg"]')[0]!
    const file = new File(['x'], 'nav.png', { type: 'image/png' })
    Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
    await input.trigger('change')
    await flushPromises()

    expect(postForm).toHaveBeenCalledWith('/company-theme/asset', expect.any(FormData))
    expect(saveFeedbackState.body).toBe('อัปโหลดโลโก้แถบเมนูแล้ว')
    expect(value(primaryHexBox(wrapper))).toBe('#abcdef')
    expect(wrapper.find('img[src="https://cdn.test/nav.png"]').exists()).toBe(true)
  })

  it('deleting a colour set asks first, then re-reads the list and shows the modal', async () => {
    presetRows = [preset(7, 'โทนหลักบริษัท')]
    const wrapper = await mountView()

    await wrapper.find('button[title="ลบชุดสี"]').trigger('click')
    expect(del).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('ยืนยันลบชุดสี “โทนหลักบริษัท”')

    del.mockResolvedValue(undefined)
    presetRows = []
    const dialogConfirm = nth(wrapper.findAll('button').filter((b) => b.text() === 'ยืนยัน'), -1)
    await dialogConfirm.trigger('click')
    await flushPromises()

    expect(del).toHaveBeenCalledWith('/theme-presets/7')
    expect(saveFeedbackState.body).toBe('ลบชุดสี "โทนหลักบริษัท" แล้ว')
    expect(wrapper.findAll('li p.text-sm.font-bold').map((p) => p.text())).not.toContain('โทนหลักบริษัท')
  })

  it('renaming a colour set quotes the name the server stored', async () => {
    presetRows = [preset(7, 'เดิม')]
    const wrapper = await mountView()
    await wrapper.find('button[title="เปลี่ยนชื่อ"]').trigger('click')
    const renameBox = wrapper.findAll('input[type="text"]').find((i) => value(i) === 'เดิม')!
    await renameBox.setValue('  ใหม่  ')
    put.mockResolvedValue({ data: preset(7, 'ใหม่') })
    presetRows = [preset(7, 'ใหม่')]

    await wrapper.findAll('button').find((b) => b.text().trim() === 'บันทึกชื่อ')!.trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/theme-presets/7', { name: 'ใหม่' })
    expect(saveFeedbackState.body).toBe('เปลี่ยนชื่อชุดสีเป็น "ใหม่" แล้ว')
    expect(wrapper.findAll('li p.text-sm.font-bold').map((p) => p.text())).toContain('ใหม่')
  })

  it('minting the short login link keeps the link on screen and raises the modal', async () => {
    const wrapper = await mountView()
    post.mockResolvedValue({ data: { login_short_link: 'https://s.example/abc' } })

    await buttonWith(wrapper, 'ย่อลิงก์ให้สั้นลง').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.body).toBe('สร้างลิงก์เข้าสู่ระบบแบบสั้นแล้ว — https://s.example/abc')
    expect(wrapper.findAll('input').some((i) => value(i) === 'https://s.example/abc')).toBe(true)
  })

  it('a failed colour-set snapshot after a successful theme save raises a PARTIAL-save modal and keeps the inline error', async () => {
    const wrapper = await mountView()
    const nameBox = wrapper.findAll('input[type="text"]').find((i) => i.attributes('placeholder')?.includes('ชุดสี'))!
    await nameBox.setValue('ชุดใหม่')
    put.mockResolvedValue({ data: structuredClone(THEME) })
    post.mockRejectedValue(new FakeApiError(422, { message: 'ชื่อซ้ำ' }))

    await buttonWith(wrapper, 'บันทึกสีปัจจุบันเป็นชุด').trigger('click')
    await flushPromises()

    // The theme PUT landed, so it is reported — under a title that says only
    // part of the click was saved, naming the half that was not.
    expect(put).toHaveBeenCalledTimes(1)
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.count).toBe(1)
    expect(saveFeedbackState.title).toBe('บันทึกแล้วบางส่วน')
    expect(saveFeedbackState.body).toContain('บันทึกการตั้งค่าธีมแล้ว')
    expect(saveFeedbackState.body).toContain('ยังไม่ได้บันทึกชุดสี "ชุดใหม่"')
    expect(wrapper.text()).toContain('บันทึกการตั้งค่าธีมแล้ว แต่')
  })

  it('when the theme save itself fails, no colour set is attempted and no modal appears', async () => {
    const wrapper = await mountView()
    const nameBox = wrapper.findAll('input[type="text"]').find((i) => i.attributes('placeholder')?.includes('ชุดสี'))!
    await nameBox.setValue('ชุดใหม่')
    put.mockRejectedValue(new FakeApiError(422, null))

    await buttonWith(wrapper, 'บันทึกสีปัจจุบันเป็นชุด').trigger('click')
    await flushPromises()

    expect(post).not.toHaveBeenCalled()
    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.text()).toContain('บันทึกการตั้งค่าไม่สำเร็จ จึงยังไม่ได้สร้างชุดสี')
  })
})
