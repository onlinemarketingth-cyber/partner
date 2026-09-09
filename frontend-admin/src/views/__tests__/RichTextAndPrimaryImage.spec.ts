/**
 * 2026-09-09 — two requests, one file, because both are about a control that
 * existed only in theory.
 *
 *   "แก้ไขรูปให้เปลี่ยนเป็นรูปหลักได้" — setPrimaryMedia() and its endpoint
 *   had existed since TASK-096. What did not exist was any way to find them:
 *   the actions lived in an `opacity-0 group-hover:opacity-100` overlay, so
 *   there was no hint they were there, and on a phone — where there is no
 *   hover at all — the primary image could not be changed by any sequence of
 *   taps. The tile's own @click opened a preview, so the one interaction a
 *   touch user could perform was the one they did not want.
 *
 *   "ให้เป็น text editor" — every long description was a textarea, so a
 *   bulleted list was typed as "- " and the storefront rendered the lot as one
 *   grey block held together by `whitespace-pre-line`.
 *
 * The editor's contract with the server is the part worth pinning: the toolbar
 * and App\Support\RichText's allowlist are one decision written twice, and an
 * empty editor must produce '' rather than Tiptap's "<p></p>" — which is
 * truthy, and would leave every `v-if="description"` in both apps rendering a
 * blank line forever.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { nextTick } from 'vue'

vi.mock('pdfjs-dist', () => ({
  GlobalWorkerOptions: { workerSrc: '' },
  getDocument: () => ({ promise: Promise.resolve({ numPages: 0 }) }),
  version: '0.0.0',
}))

const get = vi.fn()
const put = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: vi.fn(),
    put: (...args: unknown[]) => put(...args),
    patch: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: class extends Error {},
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '11' }, query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import RichTextEditor from '@/design-system/components/RichTextEditor.vue'
import RichText from '@/design-system/components/RichText.vue'
import ProductEditView from '../ProductEditView.vue'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'

// ── The editor ───────────────────────────────────────────────────────

describe('RichTextEditor', () => {
  const mountEditor = (modelValue: string | null) => mount(RichTextEditor, { props: { modelValue } })

  /**
   * The editor instance behind the component.
   *
   * Assertions go through this rather than through the rendered DOM on
   * purpose: what matters is the document Tiptap holds and the string it
   * emits — that is what reaches the server. ProseMirror's own view is also
   * only half-rendered under jsdom, so asserting on it would be testing the
   * test environment.
   */
  type EditorHandle = {
    getHTML: () => string
    commands: { selectAll: () => void; clearContent: () => void }
  }
  const editorOf = (w: ReturnType<typeof mountEditor>) => (w.vm as unknown as { editor: EditorHandle }).editor

  /** The most recent v-model emit — what the form would actually send. */
  function lastEmit(w: ReturnType<typeof mountEditor>): string {
    const emits = w.emitted('update:modelValue')
    if (!emits?.length) throw new Error('ตัวแก้ไขยังไม่ได้ส่งค่าออกมาเลย')

    return String(emits[emits.length - 1]?.[0])
  }

  it('reports an emptied editor as empty, not as "<p></p>"', async () => {
    /*
     * Tiptap's document always holds at least one paragraph, so getHTML() on a
     * blank editor returns "<p></p>" — a TRUTHY string. Stored as-is it would
     * render a section containing one blank line on the storefront forever,
     * and no amount of deleting in the editor would clear it.
     */
    const w = mountEditor('<p>ข้อความ</p>')
    await nextTick()

    editorOf(w).commands.clearContent()
    await nextTick()

    expect(lastEmit(w)).toBe('')
  })

  it('turns plain text typed before today into paragraphs', async () => {
    /*
     * Every row in these columns is plain text with real line breaks. Handed
     * to the HTML parser untouched, those breaks collapse and the admin
     * watches their formatting vanish the first time they open the field.
     */
    const w = mountEditor('บรรทัดหนึ่ง\n\nย่อหน้าสอง')
    await nextTick()

    expect(editorOf(w).getHTML()).toBe('<p>บรรทัดหนึ่ง</p><p>ย่อหน้าสอง</p>')
  })

  it('does not treat a less-than sign as the start of a tag', async () => {
    // "ราคา < 5,000" is prose, not markup. Handed to the HTML parser it would
    // eat the rest of the sentence.
    const w = mountEditor('ราคา < 5,000 บาท')
    await nextTick()

    expect(editorOf(w).getHTML()).toContain('ราคา &lt; 5,000 บาท')
  })

  it('leaves already-rich content exactly as it is', async () => {
    const w = mountEditor('<p><strong>หนา</strong></p>')
    await nextTick()

    expect(editorOf(w).getHTML()).toBe('<p><strong>หนา</strong></p>')
  })

  it('offers exactly the toolbar the server will accept', async () => {
    /*
     * The toolbar and App\Support\RichText's allowlist are one decision
     * written twice. A button here with no counterpart there is a button that
     * silently loses the admin's formatting on save.
     */
    const w = mountEditor('')
    await nextTick()

    for (const key of ['bold', 'italic', 'underline', 'strike', 'h2', 'h3', 'bulletList', 'orderedList', 'link']) {
      expect(w.find(`[data-test="rte-${key}"]`).exists()).toBe(true)
    }
  })

  it('applies a mark to the document', async () => {
    const w = mountEditor('<p>ข้อความ</p>')
    await nextTick()

    editorOf(w).commands.selectAll()
    await w.find('[data-test="rte-bold"]').trigger('click')
    await nextTick()

    expect(lastEmit(w)).toContain('<strong>')
  })

  it('assumes https for a bare domain, because a relative link would go nowhere', async () => {
    /*
     * "genesenn.com" is a RELATIVE url to a browser — it would send the reader
     * to admin.partner.syncvision.io/genesenn.com. The server also refuses
     * anything that is not http/https/mailto, so the link would be stripped on
     * save and the admin would never learn why.
     */
    const w = mountEditor('<p>ข้อความ</p>')
    await nextTick()

    editorOf(w).commands.selectAll()
    await w.find('[data-test="rte-link"]').trigger('click')
    await w.find('[data-test="rte-link-url"]').setValue('genesenn.com')
    await w.findAll('button').find((b) => b.text() === 'ตกลง')!.trigger('click')
    await nextTick()

    expect(lastEmit(w)).toContain('href="https://genesenn.com"')
  })
})

describe('RichText (the read side)', () => {
  it('renders nothing at all when the field is empty', () => {
    // Not an empty bordered box: `v-if` on the wrapper, so a product with no
    // description has no description section at all.
    expect(mount(RichText, { props: { html: null } }).find('.rich-text').exists()).toBe(false)
    expect(mount(RichText, { props: { html: '' } }).find('.rich-text').exists()).toBe(false)
  })

  it('renders the markup the server sanitised', () => {
    const w = mount(RichText, { props: { html: '<p><strong>หนา</strong></p><ul><li>ข้อ</li></ul>' } })

    expect(w.find('strong').exists()).toBe(true)
    expect(w.find('li').text()).toBe('ข้อ')
  })
})

// ── The primary image ────────────────────────────────────────────────

const PRODUCT = {
  id: 11,
  company_id: 2,
  name: 'GENESENN Vital Blueprint',
  price_satang: 100000,
  is_active: true,
  is_shared: false,
  brand: { id: 1, name: 'Genesenn' },
  category: { id: 2, name: 'Anti Aging' },
  effective_plan_type: 'unilevel',
  permissions: { update: true, delete: true, restore: true, set_commission_rule: true },
}

const media = (id: number, isPrimary: boolean) => ({
  id,
  media_type: 'image',
  source_type: 'upload',
  purpose: 'cover',
  is_primary: isPrimary,
  sort_order: 0,
  stream_url: `/media/${id}`,
  thumbnail_url: null,
  embed_url: null,
  processing_status: null,
})

async function mountProduct() {
  const auth = useAuthStore()
  auth.user = { id: 1, name: 'ผู้ดูแล', role: 'super_admin', company: null } as never

  const active = useActiveCompanyStore()
  active.companies = [{ id: 2, name: 'AIA', slug: 'aia' }]
  active.selectedId = 2

  get.mockImplementation(async (path: string) => {
    if (path.includes('/media')) return { data: [media(1, true), media(2, false)] }
    if (path.startsWith('/products/11')) return { data: PRODUCT }
    if (path.startsWith('/companies')) return { data: [{ id: 2, name: 'AIA', slug: 'aia' }] }

    return { data: [] }
  })

  const wrapper = mount(ProductEditView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot name="actions" /><slot name="tabs" /><slot /></div>' },
        Icon: true,
        EmptyState: true,
        LoadingSkeleton: true,
        BuddhistDateInput: true,
        CalendarDatePicker: true,
        AuthenticatedMedia: true,
        MediaUploadModal: true,
        MediaPreviewModal: true,
        PdfThumbnail: true,
        GroupCombobox: true,
        InfoPopover: true,
        ConfirmDialog: true,
        RichTextEditor: true,
        Teleport: true,
      },
    },
  })
  await flushPromises()

  return wrapper
}

/** Open the images tab, where the cover gallery lives. */
async function openImagesTab(w: Awaited<ReturnType<typeof mountProduct>>) {
  const tab = w.findAll('button').find((b) => b.text().includes('รูปภาพ'))
  if (!tab) throw new Error('ไม่พบแท็บรูปภาพ')
  await tab.trigger('click')
  await flushPromises()
}

describe('the primary image can actually be changed', () => {
  beforeEach(() => {
    get.mockReset()
    put.mockReset()
    put.mockResolvedValue({ data: {} })
  })

  it('shows the control without needing a hover', async () => {
    /*
     * The whole bug. The button was always in the DOM — a test asserting
     * `.exists()` would have passed on the broken version too — so this
     * asserts the CLASSES that hid it, which is what a phone could not
     * defeat.
     */
    const w = await mountProduct()
    await openImagesTab(w)

    const button = w.find('[data-test="set-primary-media"]')
    expect(button.exists()).toBe(true)

    const strip = button.element.parentElement
    expect(strip?.className).not.toContain('opacity-0')
    expect(strip?.className).not.toContain('group-hover')
  })

  it('offers it only on the images that are not already primary', async () => {
    const w = await mountProduct()
    await openImagesTab(w)

    // Two covers, one of them already primary.
    expect(w.findAll('[data-test="set-primary-media"]')).toHaveLength(1)
  })

  it('promotes the image through its own endpoint', async () => {
    const w = await mountProduct()
    await openImagesTab(w)

    await w.find('[data-test="set-primary-media"]').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/product-media/2', { is_primary: true })
  })

  it('refuses a second click while the first is still in flight', async () => {
    /*
     * Two writes racing to clear each other's primary flag is a state the
     * server transaction survives but the admin cannot explain — and on a slow
     * connection the tile looks inert, which is exactly what invites the
     * second tap.
     */
    let release: (value: unknown) => void = () => {}
    put.mockImplementation(() => new Promise((resolve) => { release = resolve }))

    const w = await mountProduct()
    await openImagesTab(w)

    await w.find('[data-test="set-primary-media"]').trigger('click')
    await w.find('[data-test="set-primary-media"]').trigger('click')

    expect(put).toHaveBeenCalledTimes(1)
    release({ data: {} })
  })
})
