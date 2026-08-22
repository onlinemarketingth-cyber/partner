/**
 * NotificationBell — the two things the 2026-08-22 report was about.
 *
 * ── 1. A TAP MUST PRODUCE A VISIBLE RESULT ──
 *
 * `onItemClick` used to close the dropdown unconditionally and then navigate
 * only if the item happened to have a link. For an item with no destination
 * — an account-status change, whose entire content is its own body text —
 * that meant the panel vanished and nothing else happened. Every symptom of
 * a broken app, produced by code that reads as correct.
 *
 * Now the panel closes ONLY when we are actually going somewhere. When we
 * are not, it stays open and the row flips out of its unread treatment under
 * the finger. That is the assertion below; nothing else in the codebase
 * records that this is deliberate, so a future "tidy up: always close it"
 * would look like a simplification.
 *
 * ── 2. UNREAD MUST NOT BE PAINTED ON A FIXED RAMP STEP ──
 *
 * It was `bg-brand-50`, the lightest lightness-mix of primary_hex, i.e. pale
 * on every tenant that can ever exist — while `--surface-card` is admin-set
 * (near-black on the reporting tenant) and `--ink-card` is derived light to
 * suit it. The unread row, the one row meant to catch the eye, was the least
 * readable thing on the screen. ADR-023 §2.2.
 *
 * jsdom cannot compute contrast, so these cases assert the only thing a unit
 * test honestly can: that the unread state is expressed in DERIVED tokens and
 * not in ramp steps. That is exactly the property that regressed.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const push = vi.fn()

vi.mock('vue-router', () => ({
  useRouter: () => ({ push, currentRoute: { value: { path: '/' } } }),
}))

vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn(async (path: string) =>
      path === '/notifications/unread-count'
        ? { data: { unread_count: 2 } }
        : // A FRESH COPY per fetch. markRead() mutates the row in place, so
          // handing out the same objects would let one test's tap flip an
          // item to read for every test that runs after it — the classic
          // order-dependent green suite.
          { data: ITEMS.map((i) => ({ ...i })) },
    ),
    post: vi.fn(async () => ({ data: {} })),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: class extends Error {},
}))

import NotificationBell from '../NotificationBell.vue'

const ITEMS = [
  {
    id: 1,
    type: 'approval_status',
    type_label: 'สถานะบัญชี',
    title: 'สถานะบัญชีของคุณถูกเปลี่ยนแปลง',
    body: 'เหตุผล: เอกสารไม่ครบ',
    link: null,
    data: null,
    is_read: false,
    read_at: null,
    created_at: '2026-08-22T03:00:00Z',
  },
  {
    id: 2,
    type: 'announcement',
    type_label: 'ข่าวสาร',
    title: 'ประกาศทดสอบ',
    body: null,
    link: '/news',
    data: { announcement_id: 7 },
    is_read: true,
    read_at: '2026-08-22T03:00:00Z',
    created_at: '2026-08-22T03:00:00Z',
  },
]

async function openBell() {
  const wrapper = mount(NotificationBell, { global: { stubs: { Icon: true } } })
  await wrapper.find('button').trigger('click')
  await flushPromises()
  return wrapper
}

/** The item buttons, i.e. everything except the bell / "อ่านทั้งหมด" / "ดูทั้งหมด". */
function rows(wrapper: ReturnType<typeof mount>) {
  return wrapper.findAll('button').filter((b) => b.text().includes('สถานะบัญชี') || b.text().includes('ข่าวสาร'))
}

describe('NotificationBell — a tap always does something visible', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    push.mockClear()
  })

  it('keeps the panel open when the notification has nowhere to go', async () => {
    const wrapper = await openBell()

    await rows(wrapper)[0].trigger('click')
    await flushPromises()

    expect(push).not.toHaveBeenCalled()
    // THE FIX. Closing here is what made a dead tap look like a broken app.
    expect(rows(wrapper).length).toBeGreaterThan(0)
  })

  it('closes the panel and navigates when there IS a destination', async () => {
    const wrapper = await openBell()

    await rows(wrapper)[1].trigger('click')
    await flushPromises()

    // /news, on the page you are already standing on, was the report.
    expect(push).toHaveBeenCalledWith({ path: '/announcements', query: { a: '7' } })
    expect(rows(wrapper).length).toBe(0)
  })
})

describe('NotificationBell — the unread state is theme-derived', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    push.mockClear()
  })

  it('tints an unread row with a derived surface, never a brand ramp step', async () => {
    const classes = rows(await openBell())[0].classes().join(' ')

    expect(classes).toContain('bg-surface-chip')
    expect(classes).toContain('border-ink-brand')
    // `brand-50` is pale on every tenant; on a dark card it made unread the
    // one row you could not read.
    expect(classes).not.toContain('bg-brand-50')
    expect(classes).not.toContain('border-brand-500')
  })

  it('leaves a read row on the card surface, with hover intact', async () => {
    const classes = rows(await openBell())[1].classes().join(' ')

    expect(classes).toContain('hover:bg-surface-chip')
    expect(classes).not.toContain('bg-surface-chip ')
  })

  it('marks unread by more than colour alone', async () => {
    // A tint is invisible to a reader who cannot separate the two shades —
    // and to anyone glancing at a phone in sunlight.
    const wrapper = await openBell()

    expect(rows(wrapper)[0].find('.rounded-full.bg-ink-brand').exists()).toBe(true)
    expect(rows(wrapper)[1].find('.rounded-full.bg-ink-brand').exists()).toBe(false)
  })
})
