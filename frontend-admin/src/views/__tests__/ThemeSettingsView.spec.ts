/**
 * ThemeSettingsView — TASK-175's four tabs over ONE form.
 *
 * WHY THIS FILE EXISTS. All four tabs are a SINGLE form committed by a SINGLE
 * `PUT /company-theme`, so "which tab is open" must never decide what gets
 * saved. The spec's rule is `v-show`, never `v-if`.
 *
 * ── A CORRECTION, MEASURED RATHER THAN ASSUMED ──
 *
 * TASK-175 §4 justifies that rule by saying an unmounted tab takes its edits
 * with it ("state dies on every tab change"). Swapping `v-show` → `v-if` and
 * re-running this file shows that is NOT true of this component: every field
 * is bound to a `ref` declared in `<script setup>`, which outlives the
 * template, so the "one PUT carries all four tabs" test below PASSES under
 * `v-if` too. The colours would not have been silently dropped.
 *
 * That is worth stating plainly rather than repeating the spec, because the
 * reason `v-show` is still correct is a different one, and it is the reason
 * these tests guard:
 *
 *  1. IT IS ONLY SAFE AS LONG AS EVERY FIELD KEEPS ITS STATE IN THE PARENT.
 *     The moment one control holds a draft of its own — a child component
 *     with an internal `ref`, an uncontrolled input, a future extraction of
 *     the colour block into `<ThemeColorsPanel>` — `v-if` starts eating
 *     edits, and nothing about that change would look dangerous in review.
 *     `v-show` makes the guarantee structural instead of incidental.
 *  2. IT ALREADY COSTS REAL UI STATE TODAY. `IconPicker` owns its open/closed
 *     `ref`; under `v-if` an expanded picker silently collapses on every trip
 *     to another tab and back. That is the last test in this file, and it is
 *     the one that fails for the reason the spec meant.
 *
 * So the assertions here are about PRESENCE, not only value: `exists()` on a
 * control belonging to a tab that is not open is what fails the moment
 * somebody "tidies" a `v-show` into a `v-if`. Verified non-tautological by
 * doing exactly that swap — 4 of the 7 tests below fail, then pass again once
 * it is restored.
 *
 * The other thing held in place here is TASK-175 §3 D2 (human decision): the
 * three per-company setting cards (ตั้งค่าวิดีโอ / การมองเห็นข้อมูลทีม /
 * การแบ่งคอมมิชชั่น) are NOT theme, are NOT one of the tabs, and keep their own
 * endpoints and their own save buttons. A future tidy-up that absorbs them
 * into a tab would make three unrelated endpoints share one button.
 *
 * The API is mocked at `@/api/client`; authorization and tenant isolation for
 * every endpoint touched here are enforced and tested server-side (BR-6).
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

// A Company Admin: no company picker, company_id resolved server-side (BR-6).
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ user: { role: 'company_admin' } }),
}))

// jsdom has no canvas; the QR image is not what this file is about.
vi.mock('@/utils/qrCode', () => ({ generateQrDataUrl: vi.fn().mockResolvedValue('') }))
vi.mock('@/utils/imageCompression', () => ({ compressImage: vi.fn() }))

import ThemeSettingsView from '../ThemeSettingsView.vue'
import { FONT_CATALOG } from '@/data/fontCatalog'

/** A real Thai family from the catalogue — never an invented font name. */
const A_THAI_FONT = FONT_CATALOG.find((f) => f.script === 'thai')!.name

const LOADED_THEME = {
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

const TAB_COLORS = 'สี'
const TAB_FONTS_LOGOS = 'ฟอนต์และโลโก้'
const TAB_NAMING = 'ชื่อและเมนู'
const TAB_OTHER = 'อื่นๆ'

async function mountView() {
  get.mockImplementation((path: string) => {
    if (path === '/me/theme') return Promise.resolve({ data: structuredClone(LOADED_THEME) })
    if (path === '/video-processing-settings') {
      return Promise.resolve({
        data: { max_upload_mb: 200, target_resolution: '720p', target_bitrate_kbps: 2500 },
      })
    }
    if (path === '/team-visibility-settings') {
      return Promise.resolve({ data: { client_visibility_level: 'counts_only', is_enabled: true } })
    }
    if (path === '/theme-presets') return Promise.resolve({ data: [] })
    return Promise.reject(new FakeApiError(404, null))
  })

  const wrapper = mount(ThemeSettingsView, {
    global: {
      stubs: {
        Icon: true,
        // Its own component, its own endpoint — tested in its own spec.
        CommissionSplitSettingCard: { template: '<div data-test="commission-split-card" />' },
      },
    },
  })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountView>>

function tab(wrapper: Wrapper, label: string) {
  const button = wrapper.findAll('button[role="tab"]').find((b) => b.text() === label)
  if (!button) throw new Error(`no tab labelled "${label}"`)
  return button
}

/** The <section> whose own <h2> is `heading`. Found whether shown or hidden. */
function card(wrapper: Wrapper, heading: string) {
  const section = wrapper
    .findAll('section')
    .find((s) => s.find('h2').exists() && s.find('h2').text() === heading)
  if (!section) throw new Error(`no card headed "${heading}" — it was DELETED, not hidden`)
  return section
}

/**
 * Is this card the one on screen?
 *
 * Reads the inline `display` that `v-show` itself writes, rather than VTU's
 * `isVisible()`. jsdom caches `getComputedStyle` per element on a DETACHED
 * tree (which is what `mount()` builds by default) and keeps answering with
 * the value from the first query — so `isVisible()` reported a card as shown
 * several re-renders after `v-show` had hidden it. An assertion that is right
 * for the wrong reason is worse than no assertion, and this is the one file
 * where visibility is the subject.
 */
function shown(el: { element: Element }): boolean {
  return (el.element as HTMLElement).style.display !== 'none'
}

/** The สี card's "สีหลัก (Primary)" hex box (the free-text one, not the swatch). */
function primaryHexBox(wrapper: Wrapper) {
  const input = card(wrapper, 'สี').findAll('input[type="text"]')[0]
  if (!input) throw new Error('no primary hex text box in the สี card')
  return input
}

/** The ฟอนต์ card's Thai-family dropdown (first of its two selects). */
function thaiFontSelect(wrapper: Wrapper) {
  const select = card(wrapper, 'ฟอนต์').findAll('select')[0]
  if (!select) throw new Error('no Thai font select in the ฟอนต์ card')
  return select
}

/** ชื่อแอป — the one label field with no menu and no icon. */
function appNameBox(wrapper: Wrapper) {
  const input = card(wrapper, 'ชื่อแอปและเมนู').find('input[placeholder="Sync Vision Agent"]')
  if (!input.exists()) throw new Error('no ชื่อแอป box in the ชื่อแอปและเมนู card')
  return input
}

/** จำนวนสินค้าแนะนำ — the storefront slot count (min 1, max 50). */
function slotCountBox(wrapper: Wrapper) {
  const input = wrapper.find('input[type="number"][max="50"]')
  if (!input.exists()) throw new Error('no จำนวนสินค้าแนะนำ box')
  return input
}

function saveButton(wrapper: Wrapper) {
  const button = wrapper.findAll('button').find((b) => b.text().includes('บันทึกธีม/แบรนด์'))
  if (!button) throw new Error('no theme save button in the header')
  return button
}

describe('ThemeSettingsView — TASK-175 four tabs, one form', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    // No `localStorage.clear()` here. The `localStorage` some machines give
    // this suite is not a working Storage — `clear` is not a function, and
    // neither is `getItem` (2026-08-12, and again on 2026-08-20 when this
    // file reported 42 failures on the human's Mac while passing in CI).
    // That is exactly why every module reading a saved preference now goes
    // through `utils/safeStorage`. Nothing in this suite writes to storage,
    // so there is nothing to clear.
  })

  it('offers exactly the four tabs of §4, opening on สี', async () => {
    const wrapper = await mountView()

    expect(wrapper.findAll('button[role="tab"]').map((b) => b.text())).toEqual([
      TAB_COLORS,
      TAB_FONTS_LOGOS,
      TAB_NAMING,
      TAB_OTHER,
    ])

    // §4's mapping: one card on สี, two on ฟอนต์และโลโก้, one on ชื่อและเมนู,
    // one on อื่นๆ. Every one of the seven original sections is still here.
    expect(shown(card(wrapper, 'สี'))).toBe(true)
    expect(shown(card(wrapper, 'ฟอนต์'))).toBe(false)
    expect(shown(card(wrapper, 'โลโก้'))).toBe(false)
    expect(shown(card(wrapper, 'ชื่อแอปและเมนู'))).toBe(false)
    expect(shown(card(wrapper, 'หน้าร้าน (Storefront) — สินค้าแนะนำ'))).toBe(false)

    /*
     * ลิงก์ Login is NOT on a tab (human, 2026-08-12: "เอาออกมาอยู่ใต้ tab
     * theme ให้ผู้ใช้ copy ง่าย ไม่ต้องไปซ่อนใน Tab"). It sits below the whole
     * tab block and is visible on every tab — asserted here rather than
     * deleted, because "always visible" is the requirement and a card that
     * quietly drifted back onto a tab would still pass a weaker test.
     */
    expect(shown(card(wrapper, 'ลิงก์ Login สำหรับตัวแทน'))).toBe(true)
    // ชุดสีที่บันทึกไว้ rides along inside the สี card (TASK-162), as §4 pairs it.
    expect(card(wrapper, 'สี').text()).toContain('ชุดสีที่บันทึกไว้')
  })

  it('switches which cards are SHOWN, tab by tab', async () => {
    const wrapper = await mountView()

    await tab(wrapper, TAB_FONTS_LOGOS).trigger('click')
    expect(shown(card(wrapper, 'ฟอนต์'))).toBe(true)
    expect(shown(card(wrapper, 'โลโก้'))).toBe(true)
    expect(shown(card(wrapper, 'สี'))).toBe(false)

    await tab(wrapper, TAB_NAMING).trigger('click')
    expect(shown(card(wrapper, 'ชื่อแอปและเมนู'))).toBe(true)
    expect(shown(card(wrapper, 'ฟอนต์'))).toBe(false)

    await tab(wrapper, TAB_OTHER).trigger('click')
    expect(shown(card(wrapper, 'ลิงก์ Login สำหรับตัวแทน'))).toBe(true)
    expect(shown(card(wrapper, 'หน้าร้าน (Storefront) — สินค้าแนะนำ'))).toBe(true)
    expect(shown(card(wrapper, 'ชื่อแอปและเมนู'))).toBe(false)
  })

  /*
   * ══════════ THE ONE THAT MATTERS ══════════
   * `exists()` — not `isVisible()`, not the value — is the assertion that
   * fails under `v-if`. A hidden-but-mounted control still holds its edit; an
   * unmounted one has already thrown it away.
   */
  it('keeps an unsaved edit alive while you are on a DIFFERENT tab (v-show, not v-if)', async () => {
    const wrapper = await mountView()

    await primaryHexBox(wrapper).setValue('#123456')
    expect((primaryHexBox(wrapper).element as HTMLInputElement).value).toBe('#123456')

    await tab(wrapper, TAB_FONTS_LOGOS).trigger('click')

    // Still MOUNTED while its tab is closed — this is the whole guarantee.
    expect(card(wrapper, 'สี').exists()).toBe(true)
    expect(primaryHexBox(wrapper).exists()).toBe(true)
    expect((primaryHexBox(wrapper).element as HTMLInputElement).value).toBe('#123456')

    // Edit on THIS tab, then look back the other way.
    await thaiFontSelect(wrapper).setValue(A_THAI_FONT)

    await tab(wrapper, TAB_COLORS).trigger('click')
    expect(thaiFontSelect(wrapper).exists()).toBe(true)
    expect((thaiFontSelect(wrapper).element as HTMLSelectElement).value).toBe(A_THAI_FONT)
    expect((primaryHexBox(wrapper).element as HTMLInputElement).value).toBe('#123456')
  })

  it('sends edits made on ALL FOUR tabs in the one PUT /company-theme', async () => {
    const wrapper = await mountView()
    put.mockResolvedValue({ data: structuredClone(LOADED_THEME) })

    // One edit per tab, switching between each — the exact sequence that used
    // to lose everything but the last panel.
    await primaryHexBox(wrapper).setValue('#123456')

    await tab(wrapper, TAB_FONTS_LOGOS).trigger('click')
    await thaiFontSelect(wrapper).setValue(A_THAI_FONT)

    await tab(wrapper, TAB_NAMING).trigger('click')
    await appNameBox(wrapper).setValue('บริษัทตัวอย่าง')

    await tab(wrapper, TAB_OTHER).trigger('click')
    await slotCountBox(wrapper).setValue('12')

    await saveButton(wrapper).trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledTimes(1)
    const [path, payload] = put.mock.calls[0] as [string, Record<string, unknown>]
    expect(path).toBe('/company-theme')
    expect(payload.primary_hex).toBe('#123456')
    expect(payload.font_family_thai).toBe(A_THAI_FONT)
    expect(payload.label_overrides).toMatchObject({ app_name: 'บริษัทตัวอย่าง' })
    expect(payload.recommended_slot_count).toBe(12)
    // A Company Admin never puts company_id on the wire (BR-6).
    expect(payload).not.toHaveProperty('company_id')
  })

  it('keeps the live preview and the single save button on every tab', async () => {
    const wrapper = await mountView()

    for (const label of [TAB_COLORS, TAB_FONTS_LOGOS, TAB_NAMING, TAB_OTHER]) {
      await tab(wrapper, label).trigger('click')

      const preview = wrapper.findAll('h2').find((h) => h.text() === 'ตัวอย่าง (Agent Portal)')
      expect(preview).toBeDefined()
      // Structural, not just present: the preview column must live OUTSIDE the
      // tabbed editor column. Inside it, it would inherit a tab's `v-show` and
      // vanish on three tabs out of four — the opposite of §4's whole point.
      expect(preview!.element.closest('.theme-tab-panel')).toBeNull()

      expect(wrapper.findAll('button').filter((b) => b.text().includes('บันทึกธีม/แบรนด์'))).toHaveLength(1)
    }
  })

  /*
   * TASK-225 — REPLACES "leaves the three per-company setting cards outside
   * the tabs (§3 D2)".
   *
   * That test pinned a requirement a later human decision reversed. TASK-175
   * §3 D2 put ตั้งค่าวิดีโอ / การมองเห็นข้อมูลทีม / คอมมิชชั่นตัวแทนร่วม on this
   * page BELOW the tabbed editor, and the old test proved they had not been
   * absorbed INTO a tab. TASK-202 (human request, 2026-08-17) then moved all
   * three off this page entirely, onto their own routes under "ตั้งค่าระบบ",
   * because stacked cards on one screen were undiscoverable.
   *
   * So the old assertion had been false since 2026-08-17 and nobody saw it:
   * this suite has been red since the same date for an unrelated reason (no
   * Pinia — see vitest.setup.ts), and a red suite hides its own regressions.
   *
   * It is REPLACED rather than deleted. "These three are not here" is still
   * worth pinning: re-absorbing them would undo TASK-202 silently, and the
   * routes they moved to are named here so the reader can find them.
   */
  it('no longer carries the three per-company setting cards — they have their own routes (TASK-202)', async () => {
    const wrapper = await mountView()

    const videoCard = wrapper.findAll('section').find((s) => s.text().includes('ตั้งค่าวิดีโอ'))
    const teamCard = wrapper.findAll('section').find((s) => s.text().includes('การมองเห็นข้อมูลทีม'))
    const splitCard = wrapper.find('[data-test="commission-split-card"]')

    expect(videoCard).toBeUndefined()
    expect(teamCard).toBeUndefined()
    expect(splitCard.exists()).toBe(false)

    // ...and neither do their save buttons, which is the half that would
    // actually break: a stray "บันทึกค่าวิดีโอ" here would write to a second
    // endpoint from a screen whose own save button says "บันทึกธีม/แบรนด์".
    const buttons = wrapper.findAll('button').map((b) => b.text())
    expect(buttons).not.toContain('บันทึกค่าวิดีโอ')
    expect(buttons).not.toContain('บันทึกการมองเห็นทีม')
  })

  /*
   * The concrete, user-visible thing `v-show` buys that `v-if` does not — see
   * the correction at the top of this file. `IconPicker` keeps its expanded
   * state in its OWN `ref`, so an unmount throws it away: open a picker, look
   * at the colours, come back, and the grid you were choosing from has shut
   * itself. This is the failure the spec was reaching for, in the one place it
   * actually happens today.
   */
  it("keeps a child component's own open state across a tab switch", async () => {
    const wrapper = await mountView()
    await tab(wrapper, TAB_NAMING).trigger('click')

    const naming = () => card(wrapper, 'ชื่อแอปและเมนู')
    const closedButtonCount = naming().findAll('button').length

    // The IconPicker toggles read "มาตรฐาน (<icon>)" while unset.
    const picker = naming()
      .findAll('button')
      .find((b) => b.text().includes('มาตรฐาน ('))
    expect(picker).toBeDefined()
    await picker!.trigger('click')

    // Open: the toggle plus one button per icon choice.
    const openButtonCount = naming().findAll('button').length
    expect(openButtonCount).toBeGreaterThan(closedButtonCount)

    await tab(wrapper, TAB_COLORS).trigger('click')
    await tab(wrapper, TAB_NAMING).trigger('click')

    expect(naming().findAll('button').length).toBe(openButtonCount)
  })
})
