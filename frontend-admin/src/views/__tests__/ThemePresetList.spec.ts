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

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ user: { role: 'company_admin' } }),
}))

vi.mock('@/utils/qrCode', () => ({ generateQrDataUrl: vi.fn().mockResolvedValue('') }))
vi.mock('@/utils/imageCompression', () => ({ compressImage: vi.fn() }))

import ThemeSettingsView from '../ThemeSettingsView.vue'

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

async function mountView(presets: unknown[]) {
  get.mockImplementation((path: string) => {
    if (path === '/me/theme') return Promise.resolve({ data: structuredClone(THEME) })
    if (path === '/video-processing-settings') {
      return Promise.resolve({ data: { max_upload_mb: 200, target_resolution: '720p', target_bitrate_kbps: 2500 } })
    }
    if (path === '/team-visibility-settings') {
      return Promise.resolve({ data: { client_visibility_level: 'counts_only', is_enabled: true } })
    }
    if (path === '/theme-presets') return Promise.resolve({ data: presets })

    return Promise.reject(new FakeApiError(404, null))
  })

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
