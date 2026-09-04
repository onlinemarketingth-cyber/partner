/**
 * "ลิงก์ทั้งบริษัท" — copying a link out of the table.
 *
 * ── WHY THIS IS WORTH A TEST ──
 *
 * The row's whole purpose is to be PASTED somewhere: a chat message, a
 * poster brief, an ad. This tab shipped without a copy button while its two
 * siblings had one from the start, and nobody noticed for a fortnight —
 * because a link that is merely ON SCREEN looks finished.
 *
 * What is pinned here is the part a refactor can silently break: that the
 * SHORT url is what lands on the clipboard (the row also holds a label and a
 * code, and a truncated display string would look identical in the cell),
 * and that a clipboard the browser refuses does not take the page down.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: class extends Error {},
  ensureCsrfCookie: vi.fn().mockResolvedValue(undefined),
}))

import CompanyLinksView from '../CompanyLinksView.vue'

const LINK = {
  id: 41,
  group: 'company_signup',
  group_label: 'สมัครตัวแทนบริษัท',
  code: 'thailife',
  short_url: 'https://apps.liveto100club.com/c/thailife',
  label: 'สมัครตัวแทนบริษัท',
  created_by_user_id: 3,
  created_by_name: 'kreangyot Ohuyhanapa',
  expires_at: null,
  revoked_at: null,
  is_usable: true,
  click_count: 27,
  unique_click_count: 17,
  conversion_count: 6,
  conversion_rate: 35.3,
  last_clicked_at: '2026-09-02T10:00:00+07:00',
  created_at: '2026-08-01T10:00:00+07:00',
}

async function mountView() {
  get.mockImplementation((path: string) =>
    Promise.resolve(path.includes('summary=1') ? { data: [] } : { data: [LINK] }),
  )

  const wrapper = mount(CompanyLinksView, {
    props: { embedded: true },
    global: { stubs: { Icon: true, HeroHeader: true, LinkQrModal: true, CompanyScopeNotice: true } },
  })
  await flushPromises()

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountView>>

const copyButton = (wrapper: Wrapper) => wrapper.find('[data-test="copy-link"]')

function stubClipboard(writeText: () => Promise<void>) {
  Object.defineProperty(navigator, 'clipboard', {
    configurable: true,
    value: { writeText: vi.fn(writeText) },
  })

  return navigator.clipboard.writeText as ReturnType<typeof vi.fn>
}

beforeEach(() => {
  get.mockReset()
})

describe('CompanyLinksView — copying a link', () => {
  it('puts the short URL on the clipboard, not the label or the code', async () => {
    const writeText = stubClipboard(() => Promise.resolve())
    const wrapper = await mountView()

    await copyButton(wrapper).trigger('click')
    await flushPromises()

    expect(writeText).toHaveBeenCalledWith('https://apps.liveto100club.com/c/thailife')
  })

  it('says so afterwards, because a silent copy is indistinguishable from a dead button', async () => {
    stubClipboard(() => Promise.resolve())
    const wrapper = await mountView()

    expect(copyButton(wrapper).text()).toContain('คัดลอก')
    expect(copyButton(wrapper).text()).not.toContain('คัดลอกแล้ว')

    await copyButton(wrapper).trigger('click')
    await flushPromises()

    expect(copyButton(wrapper).text()).toContain('คัดลอกแล้ว')
  })

  it('survives a clipboard the browser refuses', async () => {
    // Denied permission, or a page served over plain http. The URL is still
    // on screen and selectable, so the row must stay usable rather than
    // throwing an unhandled rejection through the click handler.
    stubClipboard(() => Promise.reject(new Error('NotAllowedError')))
    const wrapper = await mountView()

    await copyButton(wrapper).trigger('click')
    await flushPromises()

    expect(copyButton(wrapper).text()).not.toContain('คัดลอกแล้ว')
    expect(wrapper.text()).toContain('https://apps.liveto100club.com/c/thailife')
  })
})
