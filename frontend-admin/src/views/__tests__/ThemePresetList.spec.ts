/**
 * 2026-09-07 (human, looking at ธีม/แบรนด์): "ทำไมชุดสีที่บันทึกไว้ถึงไม่ขึ้น".
 *
 * It had. It was the last row.
 *
 * `GET /theme-presets` returns the platform's palettes FIRST — deliberately,
 * so a shared palette does not go unnoticed underneath a company's own saved
 * looks. This screen rendered that order as one flat list, and a company with
 * the six provisioned palettes therefore saw six sets nobody there had saved,
 * with the one they HAD just saved below the fold. On a panel titled
 * "ชุดสีที่บันทึกไว้", every visible row was a set nobody had saved.
 *
 * Saving said nothing either: the name box cleared, the list reloaded, and the
 * visible part looked identical. "Nothing happened" is a fair reading of a
 * screen that offers no other evidence.
 *
 * The fix must not simply invert the order — that re-creates the problem the
 * API's ordering exists to prevent, pointing the other way. Two labelled
 * groups keep both findable, and these tests hold that: own sets above,
 * platform palettes still present below, and a save that says so by name.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const put = vi.fn()
const post = vi.fn()

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
    postForm: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
  ApiError: FakeApiError,
}))

let role = 'company_admin'

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ user: { role } }),
}))

vi.mock('@/utils/qrCode', () => ({ generateQrDataUrl: vi.fn().mockResolvedValue('') }))
vi.mock('@/utils/imageCompression', () => ({ compressImage: vi.fn() }))

import ThemeSettingsView from '../ThemeSettingsView.vue'
import { useActiveCompanyStore } from '@/stores/activeCompany'

const THEME = {
  company: { name: 'GENESENN', slug: 'genesenn' },
  login_link: 'https://agent.example/login?company=genesenn',
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

function preset(id: number, name: string, isSystem: boolean) {
  return {
    id,
    name,
    is_system: isSystem,
    is_shared: false,
    is_default_for_new_companies: false,
    primary_hex: '#1e3a8a',
    accent_hex: '#f59e0b',
    nav_bg_hex: null,
    nav_bg_type: 'solid',
    nav_bg_config: null,
    card_bg_hex: null,
    background: { type: null, config: null, image_url: null },
    created_at: '2026-09-07T00:00:00Z',
  }
}

/** What the API actually returns: the platform's palettes first. */
const SYSTEM_SIX = [
  preset(1, 'ม่วงพรีเมียม', true),
  preset(2, 'เทาสุภาพ', true),
  preset(3, 'เขียวสุขภาพ', true),
  preset(4, 'น้ำเงินองค์กร', true),
  preset(5, 'ทองคลาสสิก', true),
  preset(6, 'ค่าเริ่มต้น', true),
]

const COMPANIES = [
  { id: 4, name: 'ไทยประกันชีวิต', slug: 'tli' },
  { id: 5, name: 'GENESENN', slug: 'genesenn' },
]

/**
 * A Super Admin belongs to no company, and with none scoped this whole screen
 * refuses to edit anything ("กำลังดูข้ามทุกบริษัท"). So the Super-Admin tests
 * below scope one first — which is also what the person in the report was
 * doing when they hit this.
 */
type PresetsResponder = (path: string) => Promise<{ data: unknown[] }>

async function mountView(presets: unknown[], presetsResponder?: PresetsResponder) {
  get.mockImplementation((path: string) => {
    if (path.startsWith('/companies')) return Promise.resolve({ data: COMPANIES })
    if (path === '/me/theme') return Promise.resolve({ data: structuredClone(THEME) })
    if (path === '/video-processing-settings') {
      return Promise.resolve({ data: { max_upload_mb: 200, target_resolution: '720p', target_bitrate_kbps: 2500 } })
    }
    if (path === '/team-visibility-settings') {
      return Promise.resolve({ data: { client_visibility_level: 'counts_only', is_enabled: true } })
    }
    if (path.startsWith('/theme-presets')) {
      // A responder rather than a fixed array, so a test can hold one
      // company's answer open while another company's returns — which is the
      // ordering the "ไม่ขึ้นเลย ต้อง Refresh" report describes.
      return presetsResponder ? presetsResponder(path) : Promise.resolve({ data: presets })
    }

    return Promise.reject(new FakeApiError(404, null))
  })

  const active = useActiveCompanyStore()
  active.companies = COMPANIES
  if (role === 'super_admin') active.setCompany(4)

  const wrapper = mount(ThemeSettingsView, {
    global: {
      stubs: {
        Icon: true,
        CommissionSplitSettingCard: { template: '<div />' },
      },
    },
  })
  await flushPromises()

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountView>>

/** Preset names in the order they appear on screen. */
function rowOrder(wrapper: Wrapper): string[] {
  return wrapper.findAll('li p.text-sm.font-bold').map((p) => p.text())
}

beforeEach(() => {
  role = 'company_admin'
  get.mockReset()
  put.mockReset()
  post.mockReset()
  put.mockResolvedValue({ data: structuredClone(THEME) })
  post.mockResolvedValue({ data: preset(7, 'โทนหลักบริษัท', false) })
})

describe('ThemeSettingsView — where a saved colour set appears', () => {
  it('puts the company\'s own set above the six the platform provides', async () => {
    // The report, as an ordering assertion. The API's order is unchanged; the
    // screen no longer renders it flat.
    const wrapper = await mountView([...SYSTEM_SIX, preset(7, 'โทนหลักบริษัท', false)])

    expect(rowOrder(wrapper)[0]).toBe('โทนหลักบริษัท')
  })

  it('still shows the platform palettes, under their own heading', async () => {
    /*
     * The failure mode of a careless fix: inverting the order, or filtering
     * the platform's palettes out, re-creates the problem the API's ordering
     * exists to prevent — just pointing the other way.
     */
    const wrapper = await mountView([...SYSTEM_SIX, preset(7, 'โทนหลักบริษัท', false)])

    expect(wrapper.text()).toContain('ชุดสีมาตรฐานของระบบ')
    expect(rowOrder(wrapper)).toContain('ม่วงพรีเมียม')
    expect(rowOrder(wrapper)).toHaveLength(7)
  })

  it('says where a saved set will appear when there are none yet', async () => {
    /*
     * The exact state in the screenshot: six platform rows and nothing of
     * their own. Without this line the panel looks full, and "my set is
     * missing" and "I have not saved one" are indistinguishable.
     */
    const wrapper = await mountView(SYSTEM_SIX)

    expect(wrapper.text()).toContain('ยังไม่มีชุดสีที่บันทึกเอง')
  })

  it('keeps a Super Admin\'s ชุดกลาง in the saved group, not the system one', async () => {
    // Somebody chose to create it, so it answers "did my save work". The
    // split is by is_system for exactly that reason.
    const shared = { ...preset(8, 'โทนกลางพันธมิตร', false), is_shared: true }
    const wrapper = await mountView([...SYSTEM_SIX, shared])

    expect(rowOrder(wrapper)[0]).toBe('โทนกลางพันธมิตร')
  })
})

describe('ThemeSettingsView — saving a colour set says so', () => {
  it('confirms the save by name', async () => {
    /*
     * Before this, a save cleared the name box and nothing else changed above
     * the fold. Naming the set is what lets the reader find the row rather
     * than wonder whether the click registered.
     */
    const wrapper = await mountView(SYSTEM_SIX)

    const input = wrapper.findAll('input[type="text"]').find((i) => i.attributes('placeholder')?.includes('ชุดสี'))
    expect(input).toBeTruthy()
    await input!.setValue('โทนหลักบริษัท')

    get.mockImplementation((path: string) => {
      if (path === '/theme-presets') {
        return Promise.resolve({ data: [...SYSTEM_SIX, preset(7, 'โทนหลักบริษัท', false)] })
      }
      if (path === '/me/theme') return Promise.resolve({ data: structuredClone(THEME) })

      return Promise.resolve({ data: {} })
    })

    await wrapper.findAll('button').find((b) => b.text().includes('บันทึกสีปัจจุบันเป็นชุด'))!.trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="preset-saved"]').text()).toContain('โทนหลักบริษัท')
    expect(rowOrder(wrapper)[0]).toBe('โทนหลักบริษัท')
  })
})

/**
 * 2026-09-08 — the two things the human reported about this panel on the same
 * day, which are the same panel and completely different bugs.
 */
describe('ThemeSettingsView — promoting a saved palette to ชุดกลาง', () => {
  /*
   * "ผมบันทึกสีชุด Live to 100 Club ไว้แล้ว แต่ชุดสีนี้ไม่บันทึกข้ามบริษัท
   * ทำให้บันทึกข้ามบริษัทและสามารถตั้งค่าให้ใช้ได้ทุกบริษัท โดยสิทธิ์ Super
   * Admin."
   *
   * TASK-217 put the choice on the SAVE form only, so a palette saved a minute
   * earlier could not become shared — the only route was to re-create it by
   * hand under a company you were not looking at.
   */
  const OWN = preset(7, 'Live to 100 Club', false)

  it('offers a Super Admin the control on a palette owned by one company', async () => {
    role = 'super_admin'

    const wrapper = await mountView([OWN])

    expect(wrapper.find('[data-test="share-preset"]').exists()).toBe(true)
  })

  it('does not offer it to a Company Admin', async () => {
    // Sharing puts one tenant's palette on every other tenant's screen; the
    // server strips the flag for them too.
    const wrapper = await mountView([OWN])

    expect(wrapper.find('[data-test="share-preset"]').exists()).toBe(false)
  })

  it('does not offer it on a palette that is already shared', async () => {
    role = 'super_admin'

    const wrapper = await mountView([{ ...OWN, is_shared: true }])

    expect(wrapper.find('[data-test="share-preset"]').exists()).toBe(false)
  })

  it('does not offer it on a platform palette', async () => {
    role = 'super_admin'

    const wrapper = await mountView([preset(1, 'ม่วงพรีเมียม', true)])

    expect(wrapper.find('[data-test="share-preset"]').exists()).toBe(false)
  })

  it('warns that it cannot be undone, then sends the flag', async () => {
    /*
     * One-way on purpose: company_id is the only record of who owned the
     * palette, so un-sharing would have to guess an owner. Said before the
     * click, because there is no undo to offer after it.
     */
    role = 'super_admin'
    put.mockResolvedValue({ data: { ...OWN, is_shared: true } })

    const wrapper = await mountView([OWN])
    await wrapper.find('[data-test="share-preset"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('ย้อนกลับเป็นชุดของบริษัทเดียวไม่ได้')

    await (wrapper.vm as unknown as { confirmSharePreset: () => Promise<void> }).confirmSharePreset()
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/theme-presets/7', {
      name: 'Live to 100 Club',
      is_shared: true,
    })
  })
})

/**
 * 2026-09-08, the human's follow-up question: "พอมีบริษัทใหม่เราต้องมาตั้งค่าเอง
 * หรือ Super Admin เลือกได้ให้ใช้ได้ทุกบริษัท".
 *
 * Sharing answered half of it — a ชุดกลาง already appears in every company's
 * list, including companies created later. But appearing is not wearing: a new
 * tenant still opened on the platform's colours until somebody found the row
 * and pressed "ใช้ชุดนี้". The star is the other half.
 */
describe('ThemeSettingsView — the palette new companies start on', () => {
  const SHARED = { ...preset(8, 'Live to 100 Club', false), is_shared: true }
  const STARRED = { ...SHARED, is_default_for_new_companies: true }

  it('offers a Super Admin the star on a ชุดกลาง', async () => {
    role = 'super_admin'

    const wrapper = await mountView([SHARED])

    expect(wrapper.find('[data-test="default-preset"]').exists()).toBe(true)
  })

  it('does not offer it on a palette one company owns', async () => {
    /*
     * The leak the server refuses with a 422: the palette belongs to one
     * customer, and starring it would put their colours on every tenant
     * created afterwards. Hidden here so nobody discovers that by trying —
     * promoting it to ชุดกลาง first is the separate, deliberate step.
     */
    role = 'super_admin'

    const wrapper = await mountView([preset(7, 'Live to 100 Club', false)])

    expect(wrapper.find('[data-test="default-preset"]').exists()).toBe(false)
  })

  it('does not offer it to a Company Admin', async () => {
    // What every new tenant on the platform looks like is not theirs to
    // decide, and the server strips the flag for them too.
    const wrapper = await mountView([SHARED])

    expect(wrapper.find('[data-test="default-preset"]').exists()).toBe(false)
  })

  it('tells a Company Admin which palette it is anyway', async () => {
    /*
     * Visible to everyone who can see the row, changeable by a Super Admin
     * only. "Which set is the platform's starting look" is a fair question to
     * have answered without asking anyone.
     */
    const wrapper = await mountView([STARRED])

    expect(wrapper.find('[data-test="default-preset-chip"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="default-preset"]').exists()).toBe(false)
  })

  it('promises that existing companies do not change, then sends the flag', async () => {
    /*
     * The sentence a Super Admin needs FIRST. A control labelled "for every
     * new company" reads, at the moment of clicking, as though it might
     * repaint the tenants already trading — so the dialog answers that before
     * it explains what the flag does.
     */
    role = 'super_admin'
    put.mockResolvedValue({ data: STARRED })

    const wrapper = await mountView([SHARED])
    await wrapper.find('[data-test="default-preset"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('บริษัทที่มีอยู่แล้วไม่เปลี่ยนสี')

    await (wrapper.vm as unknown as { confirmDefaultPreset: () => Promise<void> }).confirmDefaultPreset()
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/theme-presets/8', {
      name: 'Live to 100 Club',
      is_default_for_new_companies: true,
    })
  })

  it('clicking the filled star clears the choice instead of re-setting it', async () => {
    /*
     * The deliberate contrast with sharing, which is one-way. Un-starring
     * loses nothing — "new companies start on the platform's colours" is a
     * state that already existed — so the control stays put and toggles rather
     * than disappearing once used, which would leave no way to undo it.
     */
    role = 'super_admin'
    put.mockResolvedValue({ data: SHARED })

    const wrapper = await mountView([STARRED])
    const star = wrapper.find('[data-test="default-preset"]')

    expect(star.attributes('data-on')).toBe('true')

    await star.trigger('click')
    await flushPromises()

    await (wrapper.vm as unknown as { confirmDefaultPreset: () => Promise<void> }).confirmDefaultPreset()
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/theme-presets/8', {
      name: 'Live to 100 Club',
      is_default_for_new_companies: false,
    })
  })

  it('re-reads the list after starring rather than patching the row', async () => {
    /*
     * Starring one palette UNSTARS another on the server. Editing the clicked
     * row in place would leave two stars on screen until the next visit — the
     * one state this feature must never show, since it is exactly the question
     * "which one do new companies get" that the star exists to answer.
     */
    role = 'super_admin'
    put.mockResolvedValue({ data: STARRED })

    const wrapper = await mountView([SHARED])
    const before = get.mock.calls.filter(([p]) => String(p).startsWith('/theme-presets')).length

    await wrapper.find('[data-test="default-preset"]').trigger('click')
    await (wrapper.vm as unknown as { confirmDefaultPreset: () => Promise<void> }).confirmDefaultPreset()
    await flushPromises()

    expect(get.mock.calls.filter(([p]) => String(p).startsWith('/theme-presets')).length).toBe(before + 1)
  })
})

describe('ThemeSettingsView — switching company', () => {
  /*
   * "ตอนเปลี่ยนบริษัท ชุดสีขึ้นช้ากว่าที่อื่น หรือบางครั้งไม่ขึ้นเลย ต้อง
   * Refresh ถึงขึ้น."
   *
   * Two defects, and only one of them was slowness. The list had no request
   * sequencing, so whichever RESPONSE arrived last won: switch A → B while A
   * is still in flight and A's palettes land after B's, then stay on screen
   * under B's name until a reload. It needs two switches and a slow answer,
   * which is why nothing about the code looked wrong.
   */
  it('ignores a slow answer that a newer switch has already overtaken', async () => {
    role = 'super_admin'

    let releaseCompanyA: (v: unknown) => void = () => {}
    const companyAAnswer = new Promise((resolve) => { releaseCompanyA = resolve })
    let call = 0

    const wrapper = await mountView([], async () => {
      call += 1
      // Company A's answer is held open; every later one returns at once.
      if (call === 1) {
        await companyAAnswer

        return { data: [preset(90, 'PALETTE OF COMPANY A', false)] }
      }

      return { data: [preset(91, 'PALETTE OF COMPANY B', false)] }
    })

    // Switch companies while A is still in flight. B answers first.
    useActiveCompanyStore().setCompany(5)
    await flushPromises()
    expect(wrapper.text()).toContain('PALETTE OF COMPANY B')

    // A finally answers — a reply to a question nobody is asking any more.
    releaseCompanyA(null)
    await flushPromises()

    expect(wrapper.text()).toContain('PALETTE OF COMPANY B')
    expect(wrapper.text()).not.toContain('PALETTE OF COMPANY A')
  })

  it('does not keep the previous company\'s palettes while the next ones load', async () => {
    /*
     * The other half of the report. A switch used to leave the old rows on
     * screen until the new answer arrived — labelled as the new company's.
     */
    role = 'super_admin'

    let call = 0
    let releaseSecond: (v: unknown) => void = () => {}
    const second = new Promise((resolve) => { releaseSecond = resolve })

    const wrapper = await mountView([], async () => {
      call += 1
      if (call === 1) return { data: [preset(90, 'PALETTE OF COMPANY A', false)] }
      await second

      return { data: [] }
    })

    expect(wrapper.text()).toContain('PALETTE OF COMPANY A')

    useActiveCompanyStore().setCompany(5)
    await flushPromises()

    // Mid-switch: company B's answer has not arrived, and A's palette must
    // already be gone rather than sitting under B's name.
    expect(wrapper.text()).not.toContain('PALETTE OF COMPANY A')

    releaseSecond(null)
    await flushPromises()

    // Company B genuinely has none, so the panel's own empty state is the
    // honest end state — not the previous company's rows.
    expect(wrapper.text()).toContain('ยังไม่มีชุดสีที่บันทึกไว้')
    expect(wrapper.text()).not.toContain('PALETTE OF COMPANY A')
  })
})
