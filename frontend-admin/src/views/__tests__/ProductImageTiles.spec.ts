/**
 * 2026-09-09 (human: "🔴 รูปสินค้าไม่มีไฟล์ย่อเลย … รูปขนาด 2 MB ถูกโหลดมา
 * ทั้งก้อนเพื่อแสดงเป็นสี่เหลี่ยม 52 พิกเซล").
 *
 * The backend now writes a real small copy for uploaded images
 * (App\Support\Media\ImageThumbnailer) — but a thumbnail nothing asks for
 * saves nobody anything, and every image tile on this page asked for
 * `stream_url` because, until today, `thumbnail_url` was populated for
 * videos and nothing else.
 *
 * So what is pinned here is the ASKING: a tile requests the small file,
 * and falls back to the original only for the images that deliberately
 * have no thumbnail (one that was already smaller than the target).
 *
 * The full-size preview and the hero are deliberately NOT covered by
 * this rule — they are the places the original is the right file.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

vi.mock('pdfjs-dist', () => ({
  GlobalWorkerOptions: { workerSrc: '' },
  getDocument: () => ({ promise: Promise.resolve({ numPages: 0 }) }),
  version: '0.0.0',
}))

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
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '11' }, query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import ProductEditView from '../ProductEditView.vue'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'

const PRODUCT = {
  id: 11,
  company_id: 2,
  name: 'GENESENN Vital Blueprint',
  price_satang: 100000,
  is_active: true,
  is_shared: false,
  effective_plan_type: 'unilevel',
  permissions: { update: true, delete: true, restore: true, set_commission_rule: true },
}

/** `thumbnail_url: null` is the shape of an image that is already small. */
const media = (id: number, thumbnailUrl: string | null) => ({
  id,
  media_type: 'image',
  source_type: 'upload',
  purpose: 'cover',
  is_primary: id === 1,
  sort_order: 0,
  stream_url: `/media/${id}/stream`,
  thumbnail_url: thumbnailUrl,
  embed_url: null,
  processing_status: null,
})

async function mountProduct(rows: ReturnType<typeof media>[]) {
  const auth = useAuthStore()
  auth.user = { id: 1, name: 'ผู้ดูแล', role: 'super_admin', company: null } as never

  const active = useActiveCompanyStore()
  active.companies = [{ id: 2, name: 'AIA', slug: 'aia' }]
  active.selectedId = 2

  get.mockImplementation(async (path: string) => {
    if (path.includes('/media')) return { data: rows }
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

async function openImagesTab(w: Awaited<ReturnType<typeof mountProduct>>) {
  const tab = w.findAll('button').find((b) => b.text().includes('รูปภาพ'))
  if (!tab) throw new Error('ไม่พบแท็บรูปภาพ')
  await tab.trigger('click')
  await flushPromises()
}

/** Every media source the page is currently asking the network for. */
function requestedSources(w: Awaited<ReturnType<typeof mountProduct>>): string[] {
  return w
    .findAll('authenticated-media-stub')
    .map((el) => el.attributes('src'))
    .filter((src): src is string => typeof src === 'string' && src !== '')
}

describe('a small tile asks for the small file', () => {
  beforeEach(() => {
    get.mockReset()
  })

  it('never requests the full-size original for a photo that has a thumbnail', async () => {
    /*
     * The bug, stated as a rule. One 2 MB camera photo behind a 128px
     * tile is the complaint; a gallery of them is what the page
     * actually renders.
     */
    const w = await mountProduct([media(1, '/media/1/thumbnail'), media(2, '/media/2/thumbnail')])
    await openImagesTab(w)

    const sources = requestedSources(w)

    expect(sources).toContain('/media/1/thumbnail')
    expect(sources).not.toContain('/media/1/stream')
    expect(sources).not.toContain('/media/2/stream')
  })

  it('still shows an image that deliberately has no thumbnail', async () => {
    /*
     * An image already smaller than the thumbnail size gets none, on
     * purpose — the original IS the small file. Dropping the fallback
     * would blank those tiles entirely, which is a far worse bug than
     * the one being fixed.
     */
    const w = await mountProduct([media(1, null)])
    await openImagesTab(w)

    expect(requestedSources(w)).toContain('/media/1/stream')
  })
})
