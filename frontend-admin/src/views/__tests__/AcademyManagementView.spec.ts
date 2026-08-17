/**
 * AcademyManagementView — TASK-188 Phase C and Phase D.
 *
 * ── WHY THIS FILE EXISTS ──
 *
 * Both behaviours guarded here fail SILENTLY. Nothing throws, nothing renders
 * red; the screen simply stops offering something, or offers it and then does
 * something the admin was not told about.
 *
 * PHASE C — "+ เพิ่มบทเรียน" (§5.C1). The human's report was
 * "ผมหาปุ่มไม่เจอในการเพิ่มบทเรียน". There was exactly one such button, inside a
 * Section card, behind TWO conditions: the card itself only exists in the
 * two-pane layout once a Section has been SELECTED, and the button was then
 * hidden AGAIN by `v-if="!isWideLayout || !selectedLesson"` while a lesson was
 * selected. So on first render it was not in the DOM at all, and the moment you
 * clicked a lesson to look for it, it disappeared.
 *
 * The tests below therefore cover the two states the old code got wrong:
 * NOTHING selected, and a LESSON selected. A test that only mounted the view
 * and looked for the string "เพิ่มบทเรียน" anywhere would have passed against
 * the broken build in narrow layout, which is why layout is set explicitly.
 *
 * Amended 2026-08-16: the button now lives on each Section row rather than in
 * the top action row (`add-lesson-row`, was `add-lesson-top`). The states being
 * guarded did not change — only where the affordance is drawn.
 *
 * PHASE D — changing a lesson's content type (§6.D4). This write DELETES the
 * stored file and CLEARS the learner progress rows. Three things have to hold
 * and each is independently losable:
 *
 *   1. The numbers in the confirmation come from
 *      GET /module-lessons/{id}/content-type-change-impact. A refactor that
 *      "simplifies" the body into a fixed sentence still shows a dialog, still
 *      asks for confirmation, and now tells the admin nothing true.
 *   2. The reassurance ("completions are kept") is shown. ag-dev measured that
 *      `completions_are_kept` is always true; admins assume the opposite, so
 *      omitting it is the difference between an admin proceeding and an admin
 *      rebuilding the lesson by hand.
 *   3. NOTHING is written before the dialog is accepted. This is the assertion
 *      that a `await api.put(...)` moved one line too early would break, and
 *      the only symptom would be files disappearing on save.
 *
 * The API is mocked at `@/api/client`; authorization and the retype's server
 * behaviour are the backend's (ag-dev's tests). This file is about what the
 * screen offers and what it sends.
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
    patch: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    postFileWithProgress: vi.fn(),
  },
  ApiError: FakeApiError,
}))

// A Company Admin: no Super Admin company picker in the way (BR-6).
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ user: { role: 'company_admin' } }),
}))

// pdfjs-dist is a STATIC import of the preview strip and the preview modal, and
// it throws `ReferenceError: DOMMatrix is not defined` at import time under
// jsdom. Both are stubbed at the module level (not just rendered away) so the
// import never happens; neither is what this file is about.
vi.mock('@/design-system/components/PdfThumbnail.vue', () => ({
  default: { name: 'PdfThumbnail', template: '<div />' },
}))
vi.mock('@/design-system/components/LessonPreviewModal.vue', () => ({
  default: { name: 'LessonPreviewModal', template: '<div />' },
}))

import AcademyManagementView from '../AcademyManagementView.vue'

// ── Fixtures ────────────────────────────────────────────────────────────
const CERT_TIER = { id: 1, key: 'basic', name: 'Basic' }

function makeLesson(overrides: Record<string, unknown> = {}) {
  return {
    id: 501,
    module_id: 301,
    title: 'วิดีโอแนะนำแพ็กเกจ',
    content_type: 'video',
    source_type: 'embed',
    content_ref: 'https://www.youtube.com/watch?v=abc',
    stream_url: null,
    inline_url: null,
    is_downloadable: false,
    duration_seconds: null,
    page_count: null,
    processing_status: null,
    sort_order: 0,
    xp_reward: 10,
    is_published: true,
    is_optional: false,
    quiz_question_count: 0,
    quiz_unlocked: true,
    quiz_blocks_completion: false,
    quiz_passed: null,
    quiz_pass_percent: null,
    quiz_id: null,
    ...overrides,
  }
}

function makeModule(overrides: Record<string, unknown> = {}) {
  return {
    id: 301,
    company_id: 1,
    title: 'บทนำ',
    cert_tier: CERT_TIER,
    product: null,
    is_published: true,
    sort_order: 0,
    enforce_sequential: false,
    drip_days: null,
    lesson_count: 1,
    required_lesson_count: 1,
    optional_lesson_count: 0,
    lessons: [makeLesson()],
    ...overrides,
  }
}

/**
 * The impact payload exactly as ag-dev's endpoint sends it. The numbers are
 * distinctive on purpose: "7" and "3" cannot be produced by the view guessing.
 */
const IMPACT = {
  content_type: 'video',
  learners_with_progress: 7,
  progress_will_be_reset: true,
  learners_completed: 3,
  completions_are_kept: true,
  stored_file_will_be_deleted: true,
  is_downloadable_will_reset: true,
  quiz_id: 900,
  quiz_stays_attached: true,
}

const COMPLETION_SETTINGS = {
  video_watch_percent: 80,
  pdf_read_percent: 80,
  quiz_pass_percent: 70,
}

/**
 * The screen is a two-pane grid at `lg:` and a stacked accordion below it, and
 * that split is asked in JS (`window.matchMedia`), not with `lg:` classes — so
 * the layout has to be chosen before mount.
 */
function stubMatchMedia(wide: boolean) {
  Object.defineProperty(window, 'matchMedia', {
    writable: true,
    configurable: true,
    value: vi.fn().mockImplementation((query: string) => ({
      matches: wide,
      media: query,
      onchange: null,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      addListener: vi.fn(),
      removeListener: vi.fn(),
      dispatchEvent: vi.fn(),
    })),
  })
}

function stubLoads(modules: ReturnType<typeof makeModule>[]) {
  get.mockImplementation((path: string) => {
    if (path.startsWith('/cert-tiers')) return Promise.resolve({ data: [CERT_TIER] })
    if (path.startsWith('/products')) return Promise.resolve({ data: [] })
    if (path.startsWith('/modules')) return Promise.resolve({ data: modules, meta: { last_page: 1 } })
    if (path.startsWith('/exams')) return Promise.resolve({ data: [] })
    if (path.startsWith('/academy-completion-settings'))
      return Promise.resolve({ data: COMPLETION_SETTINGS })
    if (path.includes('/content-type-change-impact')) return Promise.resolve({ data: IMPACT })

    return Promise.resolve({ data: [] })
  })
}

async function mountView() {
  const wrapper = mount(AcademyManagementView, {
    global: { stubs: { Icon: true, AuthenticatedMedia: true } },
  })
  await flushPromises()

  return wrapper
}

/** The one ConfirmDialog currently on screen (they render with `v-if`). */
function openDialogText(wrapper: { text: () => string }): string {
  return wrapper.text()
}

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  post.mockReset()
  stubMatchMedia(false)
})

describe('AcademyManagementView — TASK-188 §5, the add-lesson affordance', () => {
  /*
   * AMENDED 2026-08-16 (human, after seeing Phase C on screen). The button
   * moved off the top action row and onto EACH SECTION ROW, right-aligned
   * beside that Section's lesson count — `data-test="add-lesson-row"`, where
   * the retired top-level one was `add-lesson-top`.
   *
   * The assertion these tests exist to protect is UNCHANGED and is the one
   * §5.C1 bought: the affordance is on screen on FIRST RENDER, with nothing
   * selected. Only the selector moved. It is asserted at BOTH widths on
   * purpose — the outline panel that carries the button is `v-if="isWideLayout"`
   * (1024px), and this Admin is used on a tablet below that, so a version of
   * this change that only fed the two-pane layout would pass a wide-only test
   * while regressing the actual device.
   */
  it('offers "+ เพิ่มบทเรียน" on FIRST RENDER, with nothing selected', async () => {
    stubLoads([makeModule()])
    const wrapper = await mountView()

    // Nothing has been clicked: no Section selected, no lesson selected. This
    // is the exact state the human was in when they could not find the button.
    const row = wrapper.find('[data-test="add-lesson-row"]')
    expect(row.exists()).toBe(true)
    expect(row.text()).toContain('เพิ่มบทเรียน')

    // The Section create stays where it always was — it is the only top-level
    // create on this tab, because a Section is the only thing with no parent.
    expect(wrapper.text()).toContain('+ เพิ่ม Section')
    // Moved, not duplicated.
    expect(wrapper.find('[data-test="add-lesson-top"]').exists()).toBe(false)
  })

  it('offers it on first render in the two-pane layout too, where no Section card is drawn yet', async () => {
    stubMatchMedia(true)
    stubLoads([makeModule()])
    const wrapper = await mountView()

    // In wide mode `inspectorGroups` is EMPTY until a Section is selected, so
    // the in-card button genuinely does not exist yet. The outline row's button
    // is the entry point at this width; without it the screen would offer no
    // way to add a lesson at all.
    expect(wrapper.find('[data-test="add-lesson-section"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="add-lesson-row"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="add-lesson-top"]').exists()).toBe(false)
  })

  it('is offered once per SECTION, not once per cert-tier group', async () => {
    stubMatchMedia(true)
    // Two Sections sharing one cert tier: a per-tier button would render once,
    // and would not know which of the two it was adding to.
    stubLoads([makeModule(), makeModule({ id: 302, title: 'บทที่สอง', lessons: [] })])
    const wrapper = await mountView()

    expect(wrapper.findAll('[data-test="add-lesson-row"]')).toHaveLength(2)
  })

  it('still offers it on a Section with ZERO lessons — the case where it matters most', async () => {
    stubMatchMedia(true)
    stubLoads([makeModule({ lessons: [], lesson_count: 0, required_lesson_count: 0 })])
    const wrapper = await mountView()

    expect(wrapper.find('[data-test="add-lesson-row"]').exists()).toBe(true)
  })

  it('opens the lesson form in ONE click, without making the admin pick a Section first', async () => {
    stubLoads([makeModule()])
    const wrapper = await mountView()

    await wrapper.find('[data-test="add-lesson-row"]').trigger('click')
    await flushPromises()

    // The form itself, not just a scrolled-to button. One trigger, not two:
    // select-then-hunt-for-the-form is the thing this replaced.
    expect(wrapper.text()).toContain('ประเภทเนื้อหา')
    expect(wrapper.find('[data-test="add-lesson-section"]').exists()).toBe(true)
  })

  it('opens it in one click from the outline row too, selecting that Section on the way', async () => {
    stubMatchMedia(true)
    stubLoads([makeModule()])
    const wrapper = await mountView()

    await wrapper.find('[data-test="add-lesson-row"]').trigger('click')
    await flushPromises()

    // Selecting is what draws the Section's card in the right pane at all, so
    // this asserts both halves of the single action.
    expect(wrapper.find('[data-test="add-lesson-section"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('ประเภทเนื้อหา')
  })

  it('keeps the Section-level button while a LESSON is selected — the condition §5.C1 removed', async () => {
    stubMatchMedia(true)
    stubLoads([makeModule()])
    const wrapper = await mountView()

    // Select the lesson in the outline, exactly as the human did.
    const lessonRow = wrapper
      .findAll('[role="button"]')
      .find((el) => el.text().includes('วิดีโอแนะนำแพ็กเกจ'))
    expect(lessonRow).toBeDefined()
    await lessonRow!.trigger('click')
    await flushPromises()

    // The old `v-if="!isWideLayout || !selectedLesson"` made this false.
    expect(wrapper.find('[data-test="add-lesson-section"]').exists()).toBe(true)
  })

  it('with NO Sections at all, points at the one create that is available', async () => {
    // The consequence of the move: with no Section there is nowhere to put a
    // lesson, so there is correctly no add-lesson button anywhere — which would
    // leave a brand-new company staring at "ยังไม่มี Section" and no next step.
    stubLoads([])
    const wrapper = await mountView()

    expect(wrapper.find('[data-test="add-lesson-row"]').exists()).toBe(false)
    const empty = wrapper.find('[data-test="no-sections-empty"]')
    expect(empty.exists()).toBe(true)

    const cta = empty.find('button')
    expect(cta.text()).toContain('เพิ่ม Section')
    expect((cta.element as HTMLButtonElement).disabled).toBe(false)

    // …and it actually opens the form, rather than being a decorative CTA.
    await cta.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('ชื่อ Section')
  })
})

describe('AcademyManagementView — TASK-188 §6, changing a lesson content type', () => {
  /** Opens the accordion and then the lesson's full edit form. */
  async function openLessonEditForm() {
    stubLoads([makeModule()])
    const wrapper = await mountView()

    const manage = wrapper
      .findAll('button')
      .find((b) => b.text().includes('จัดการบทเรียน'))
    expect(manage).toBeDefined()
    await manage!.trigger('click')
    await flushPromises()

    await wrapper.find('[data-test="edit-lesson"]').trigger('click')
    await flushPromises()

    return wrapper
  }

  /** Retypes video → pdf (external), which needs a fresh content_ref. */
  async function requestRetype(wrapper: Awaited<ReturnType<typeof openLessonEditForm>>) {
    await wrapper.find('[data-test="edit-lesson-content-type"]').setValue('pdf')
    await wrapper.find('[data-test="retype-content-ref"]').setValue('https://example.test/doc.pdf')
    await wrapper.find('[data-test="edit-lesson-save"]').trigger('click')
    await flushPromises()
  }

  it('the edit form carries a content-type control at all', async () => {
    const wrapper = await openLessonEditForm()

    // Before TASK-188 this select existed only in the CREATE form; the edit
    // form had no `content_type` field, so a wrong choice was permanent.
    const select = wrapper.find('[data-test="edit-lesson-content-type"]')
    expect(select.exists()).toBe(true)
    expect((select.element as HTMLSelectElement).value).toBe('video')
  })

  it('reads the impact from the endpoint and writes NOTHING until the dialog is accepted', async () => {
    const wrapper = await openLessonEditForm()
    await requestRetype(wrapper)

    expect(get).toHaveBeenCalledWith('/module-lessons/501/content-type-change-impact')
    // The write is what deletes the file and clears the progress rows.
    expect(put).not.toHaveBeenCalled()
    expect(post).not.toHaveBeenCalled()
  })

  it('states the measured numbers, not a vague warning', async () => {
    const wrapper = await openLessonEditForm()
    await requestRetype(wrapper)

    const text = openDialogText(wrapper)
    // learners_with_progress = 7, straight from the response.
    expect(text).toContain('7')
    expect(text).toContain('ความคืบหน้า')
    // stored_file_will_be_deleted — and the admin must supply new content now.
    expect(text).toContain('ไฟล์เดิม')
    // is_downloadable_will_reset
    expect(text).toContain('ดาวน์โหลด')
  })

  it('shows the REASSURING half: completions are kept, and how many', async () => {
    const wrapper = await openLessonEditForm()
    await requestRetype(wrapper)

    const text = openDialogText(wrapper)
    // learners_completed = 3. Admins assume completions are wiped; ag-dev
    // measured that they are not. Saying so is what stops an admin abandoning
    // the change and rebuilding the lesson by hand.
    expect(text).toContain('3')
    expect(text).toContain('ยังนับว่าเรียนจบเหมือนเดิม')
    // quiz_stays_attached — reassure, do not warn.
    expect(text).toContain('แบบทดสอบท้ายบทที่ผูกไว้ยังอยู่ครบ')
  })

  it('sends the new type ONLY after confirm, with the new content in the same request', async () => {
    const wrapper = await openLessonEditForm()
    await requestRetype(wrapper)

    const confirmButton = wrapper.findAll('button').find((b) => b.text() === 'ยืนยัน')
    expect(confirmButton).toBeDefined()
    await confirmButton!.trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledTimes(1)
    const [path, payload] = put.mock.calls[0] as [string, Record<string, unknown>]
    expect(path).toBe('/module-lessons/501')
    expect(payload.content_type).toBe('pdf')
    // The API requires the new type's content spec in the SAME request.
    expect(payload.content_ref).toBe('https://example.test/doc.pdf')
  })

  it('refuses the retype when the new type has no content yet, and asks for nothing', async () => {
    const wrapper = await openLessonEditForm()

    await wrapper.find('[data-test="edit-lesson-content-type"]').setValue('pdf')
    // content_ref deliberately left as-is → cleared, so the spec is incomplete.
    await wrapper.find('[data-test="retype-content-ref"]').setValue('')
    await wrapper.find('[data-test="edit-lesson-save"]').trigger('click')
    await flushPromises()

    // Neither the impact read nor the write happens: a confirmation dialog for
    // a change the API would 422 is worse than no dialog.
    expect(get).not.toHaveBeenCalledWith('/module-lessons/501/content-type-change-impact')
    expect(put).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('ใส่ลิงก์เนื้อหาของประเภทใหม่ก่อน')
  })

  it('saving WITHOUT changing the type is not a retype: no impact read, no dialog', async () => {
    const wrapper = await openLessonEditForm()

    await wrapper.find('[data-test="edit-lesson-save"]').trigger('click')
    await flushPromises()

    // Sending the lesson's CURRENT content_type would be a no-op server-side,
    // but a confirmation dialog on every ordinary save would train the admin to
    // dismiss the one that matters.
    expect(get).not.toHaveBeenCalledWith('/module-lessons/501/content-type-change-impact')
    expect(put).toHaveBeenCalledTimes(1)
    const [, payload] = put.mock.calls[0] as [string, Record<string, unknown>]
    expect(payload.content_type).toBeUndefined()
  })
})
