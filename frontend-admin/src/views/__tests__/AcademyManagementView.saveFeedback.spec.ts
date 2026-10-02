/**
 * AcademyManagementView — ADR-052, "saved" is said once, and only when true.
 *
 * Owner, 2026-10-01: every save must raise a dialog saying it saved, and the
 * admin must not need F5 to see that it really did. On this screen that meant
 * three different defects, all pinned below:
 *
 *   1. WRITES THAT SAID NOTHING (or a 2-second "บันทึกแล้ว"). Each now raises
 *      the shared dialog — only after the request resolved AND the screen was
 *      re-read, never before.
 *   2. FORMS THAT KEPT WHAT WAS TYPED. The company completion thresholds, the
 *      Section gear and the per-lesson quiz settings all left the admin's input
 *      in place after a save, so a value the server stored differently still
 *      read as stored. The mocks below deliberately answer with values that
 *      differ from what was typed; the assertions are on the SERVER's values.
 *   3. DELETES THAT ASKED NOTHING (exam, exam question, exam option) and a
 *      create with no error handling at all (submitExam).
 *
 * `saveFeedbackState` is the dialog's state; vitest.setup.ts resets it before
 * every test, so `show === false` at the start is guaranteed.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { saveFeedbackState, SAVED_BUT_STALE_BODY } from '@/composables/useSaveFeedback'

const get = vi.fn()
const put = vi.fn()
const post = vi.fn()
const del = vi.fn()

const { FakeApiError } = vi.hoisted(() => ({
  FakeApiError: class extends Error {
    constructor(
      public status: number,
      message: string,
      public body: unknown = {},
    ) {
      super(message)
    }
  },
}))

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    put: (...args: unknown[]) => put(...args),
    post: (...args: unknown[]) => post(...args),
    patch: vi.fn(),
    delete: (...args: unknown[]) => del(...args),
    postForm: vi.fn(),
    postFileWithProgress: vi.fn(),
  },
  ApiError: FakeApiError,
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ user: { role: 'company_admin' } }),
}))

// pdfjs-dist throws at import time under jsdom (see AcademyManagementView.spec.ts).
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

const EXAM = { id: 7, title: 'สอบ Basic', passing_score: 70, cert_tier: CERT_TIER }

/** What the "server" currently holds. Tests change it to model a write landing. */
const server: {
  modules: ReturnType<typeof makeModule>[]
  exams: (typeof EXAM)[]
  examQuestions: unknown[]
  lessonProgress: unknown[]
  progressRows: unknown[]
} = { modules: [], exams: [], examQuestions: [], lessonProgress: [], progressRows: [] }

function stubLoads() {
  get.mockImplementation((path: string) => {
    if (path.startsWith('/cert-tiers')) return Promise.resolve({ data: [CERT_TIER] })
    if (path.startsWith('/products')) return Promise.resolve({ data: [] })
    if (path.startsWith('/modules')) return Promise.resolve({ data: server.modules, meta: { last_page: 1 } })
    if (path.startsWith('/exams/7/questions')) return Promise.resolve({ data: server.examQuestions })
    if (path.startsWith('/exams')) return Promise.resolve({ data: server.exams })
    if (path.startsWith('/academy-completion-settings'))
      return Promise.resolve({ data: { video_watch_percent: 80, pdf_read_percent: 80, quiz_pass_percent: 70 } })
    if (path.endsWith('/progress')) return Promise.resolve({ data: server.lessonProgress })
    if (path.startsWith('/academy-progress-summary'))
      return Promise.resolve({
        data: server.progressRows,
        meta: { current_page: 1, last_page: 1, per_page: 25, total: server.progressRows.length },
        summary: { company_id: 1, agent_count: server.progressRows.length, required_lesson_count: 0, sections: [] },
        computed_at: '2026-10-01T00:00:00Z',
      })

    return Promise.resolve({ data: [] })
  })
}

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

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((res, rej) => {
    resolve = res
    reject = rej
  })

  return { promise, resolve, reject }
}

async function mountView() {
  const wrapper = mount(AcademyManagementView, {
    global: { stubs: { Icon: true, AuthenticatedMedia: true } },
  })
  await flushPromises()

  return wrapper
}

function buttonWithText(wrapper: VueWrapper, text: string, exact = false) {
  const found = wrapper
    .findAll('button')
    .find((b) => (exact ? b.text().trim() === text : b.text().includes(text)))
  expect(found, `button "${text}"`).toBeDefined()

  return found!
}

/** The ConfirmDialog that is actually open — the screen mounts several. */
async function confirmOpenDialog(wrapper: VueWrapper) {
  const dialog = wrapper.findAllComponents({ name: 'ConfirmDialog' }).find((d) => d.props('show') === true)
  expect(dialog, 'an open ConfirmDialog').toBeDefined()
  dialog!.vm.$emit('confirm')
  await flushPromises()
}

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  post.mockReset()
  del.mockReset()
  server.modules = [makeModule()]
  server.exams = []
  server.examQuestions = []
  server.lessonProgress = []
  server.progressRows = []
  stubLoads()
  stubMatchMedia(false)
})

describe('AcademyManagementView — ADR-052 company completion thresholds', () => {
  async function openThresholdsForm(wrapper: VueWrapper) {
    await buttonWithText(wrapper, 'เกณฑ์การเรียนจบของบริษัท').trigger('click')
    await flushPromises()

    return wrapper.findAll('input[type="number"][max="100"]').slice(0, 3)
  }

  it('raises the dialog only AFTER the PUT resolved, and the form then shows the SERVER values', async () => {
    const wrapper = await mountView()
    const inputs = await openThresholdsForm(wrapper)
    await inputs[0]!.setValue('55')
    await inputs[1]!.setValue('66')
    await inputs[2]!.setValue('77')

    const write = deferred<unknown>()
    put.mockReturnValueOnce(write.promise)
    await buttonWithText(wrapper, 'บันทึกเกณฑ์').trigger('click')
    await flushPromises()

    // In flight: nothing may claim it saved yet.
    expect(saveFeedbackState.show).toBe(false)

    // The server stores something other than what was typed.
    write.resolve({ data: { video_watch_percent: 90, pdf_read_percent: 85, quiz_pass_percent: 75 } })
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('90%')
    expect(saveFeedbackState.body).toContain('85%')
    expect(saveFeedbackState.body).toContain('75%')
    // LOCAL→SERVER: the form used to keep 55/66/77 after the save.
    const after = wrapper.findAll('input[type="number"][max="100"]').slice(0, 3)
    expect(after.map((i) => (i.element as HTMLInputElement).value)).toEqual(['90', '85', '75'])
    // The inline flash is gone — the dialog replaces it.
    expect(wrapper.text()).not.toContain('บันทึกแล้ว')
  })

  it('raises NO dialog when the PUT fails, and shows the error', async () => {
    const wrapper = await mountView()
    const inputs = await openThresholdsForm(wrapper)
    await inputs[0]!.setValue('0')

    put.mockRejectedValueOnce(new FakeApiError(422, 'ค่าต้องอยู่ระหว่าง 1–100'))
    await buttonWithText(wrapper, 'บันทึกเกณฑ์').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.text()).toContain('ค่าต้องอยู่ระหว่าง 1–100')
  })
})

describe('AcademyManagementView — ADR-052 Section gear and lesson quiz settings re-sync from the server', () => {
  it('Section release settings: the form shows the stored drip, not the typed one', async () => {
    const wrapper = await mountView()
    await wrapper.find('button[title="การเปิดให้เรียน (ลำดับ / หน่วงเวลา)"]').trigger('click')
    await flushPromises()

    const drip = wrapper.find('input[placeholder="เว้นว่าง = เปิดให้เรียนทันที"]')
    await drip.setValue('7')

    put.mockResolvedValueOnce({ data: makeModule({ title: 'บทนำ (ที่บันทึก)', drip_days: 14 }) })
    await buttonWithText(wrapper, 'บันทึกการตั้งค่า', true).trigger('click')
    await flushPromises()

    // The typed drip reaches the server at all. A type=number v-model hands
    // back a NUMBER, and the old `.trim()` on it threw before the PUT — every
    // save after touching this field failed with a generic error.
    expect(put).toHaveBeenCalledWith('/modules/301', { enforce_sequential: false, drip_days: 7, is_published: true })
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('บันทึกการตั้งค่า Section “บทนำ (ที่บันทึก)” แล้ว')
    expect((wrapper.find('input[placeholder="เว้นว่าง = เปิดให้เรียนทันที"]').element as HTMLInputElement).value).toBe('14')
    expect(wrapper.text()).not.toContain('บันทึกแล้ว')
  })

  it('lesson quiz settings: the summary reads the SAVED lesson, and the form is refilled after save', async () => {
    stubMatchMedia(true)
    const wrapper = await mountView()

    // Select the lesson in the outline — that opens its quiz settings.
    await wrapper.findAll('[role="button"]').find((n) => n.text().includes('วิดีโอแนะนำแพ็กเกจ'))!.trigger('click')
    await flushPromises()

    const summary = () => wrapper.find('[data-test="quiz-settings-summary"]').text()
    expect(summary()).toContain('ตามค่าของบริษัท')

    await wrapper.find('input[placeholder="ใช้ค่าของบริษัท"]').setValue('55')
    // Typing is not saving: the summary must not echo the half-typed value.
    expect(summary()).toContain('ตามค่าของบริษัท')
    expect(summary()).not.toContain('55%')

    // The server stores 60 (e.g. a different value won a race) — the screen must say 60.
    put.mockImplementationOnce(() => {
      server.modules = [makeModule({ lessons: [makeLesson({ quiz_pass_percent: 60 })] })]

      return Promise.resolve({ data: makeLesson({ quiz_pass_percent: 60 }) })
    })
    await buttonWithText(wrapper, 'บันทึกการตั้งค่า', true).trigger('click')
    await flushPromises()

    // Same type=number cast as the Section gear: the typed 55 must be SENT.
    expect(put).toHaveBeenCalledWith('/module-lessons/501', { quiz_pass_percent: 55, quiz_blocks_completion: false })
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('บันทึกการตั้งค่าแบบทดสอบของบทเรียน “วิดีโอแนะนำแพ็กเกจ” แล้ว')
    expect((wrapper.find('input[placeholder="ใช้ค่าของบริษัท"]').element as HTMLInputElement).value).toBe('60')
    expect(summary()).toContain('60%')
  })

  it('a save whose re-read fails still says it saved — but that the screen may be behind', async () => {
    const wrapper = await mountView()
    await buttonWithText(wrapper, '+ เพิ่ม Section').trigger('click')
    await wrapper.find('form input[required]').setValue('Section ใหม่')
    await wrapper.find('form select').setValue('1')

    post.mockImplementationOnce(() => {
      // The write lands, then every re-read fails.
      get.mockRejectedValue(new FakeApiError(500, 'down'))

      return Promise.resolve({ data: makeModule({ id: 302, title: 'Section ใหม่' }) })
    })
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe(SAVED_BUT_STALE_BODY)
  })
})

describe('AcademyManagementView — ADR-052 exams', () => {
  async function openExamsTab() {
    const wrapper = await mountView()
    await buttonWithText(wrapper, 'แบบประเมินผล', true).trigger('click')
    await flushPromises()

    return wrapper
  }

  async function fillExamForm(wrapper: VueWrapper) {
    await buttonWithText(wrapper, '+ เพิ่มแบบประเมินผล').trigger('click')
    const form = wrapper.find('form')
    await form.find('input[required]').setValue('สอบที่พิมพ์')
    await form.find('select').setValue('1')

    return form
  }

  it('submitExam: dialog after the POST resolved, quoting the SERVER title, list re-read', async () => {
    const wrapper = await openExamsTab()
    const form = await fillExamForm(wrapper)

    const write = deferred<unknown>()
    post.mockReturnValueOnce(write.promise)
    await form.trigger('submit')
    await flushPromises()
    expect(saveFeedbackState.show).toBe(false)

    server.exams = [{ ...EXAM, title: 'สอบที่เซิร์ฟเวอร์เก็บ' }]
    write.resolve({ data: { ...EXAM, title: 'สอบที่เซิร์ฟเวอร์เก็บ' } })
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('เพิ่มแบบประเมินผล “สอบที่เซิร์ฟเวอร์เก็บ” แล้ว')
    expect(wrapper.text()).toContain('สอบที่เซิร์ฟเวอร์เก็บ')
  })

  it('submitExam failing: NO dialog, the error is shown (it used to be an unhandled rejection)', async () => {
    const wrapper = await openExamsTab()
    const form = await fillExamForm(wrapper)

    post.mockRejectedValueOnce(new FakeApiError(422, 'ชื่อนี้ถูกใช้แล้ว'))
    await form.trigger('submit')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.text()).toContain('บันทึกไม่สำเร็จ — ชื่อนี้ถูกใช้แล้ว')
    // The form stays open with what was typed, so the admin can fix it.
    expect(wrapper.find('form').exists()).toBe(true)
  })

  it('deleteExam asks first, deletes on confirm, then raises the dialog over the re-read list', async () => {
    server.exams = [EXAM]
    const wrapper = await openExamsTab()

    await wrapper.find('[data-test="delete-exam"]').trigger('click')
    await flushPromises()
    expect(del).not.toHaveBeenCalled()
    expect(saveFeedbackState.show).toBe(false)

    del.mockImplementationOnce(() => {
      server.exams = []

      return Promise.resolve(undefined)
    })
    await confirmOpenDialog(wrapper)

    expect(del).toHaveBeenCalledWith('/exams/7')
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('ลบแบบประเมินผล “สอบ Basic” แล้ว')
    expect(wrapper.text()).not.toContain('สอบ Basic')
  })

  it('deleting an exam OPTION asks first too, then re-reads the bank', async () => {
    server.exams = [EXAM]
    server.examQuestions = [
      {
        id: 70,
        exam_id: 7,
        question_text: 'ข้อหนึ่ง',
        sort_order: 0,
        options: [{ id: 700, exam_question_id: 70, option_text: 'ตัวเลือกเอ', is_correct: false, sort_order: 0 }],
      },
    ]
    const wrapper = await openExamsTab()
    await buttonWithText(wrapper, 'จัดการคำถาม').trigger('click')
    await flushPromises()

    await wrapper.find('[data-test="delete-exam-option"]').trigger('click')
    await flushPromises()
    expect(del).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('ลบตัวเลือก “ตัวเลือกเอ” ยืนยันหรือไม่?')

    const readsBefore = get.mock.calls.filter(([p]) => p === '/exams/7/questions').length
    del.mockResolvedValueOnce(undefined)
    await confirmOpenDialog(wrapper)

    expect(del).toHaveBeenCalledWith('/exam-question-options/700')
    expect(get.mock.calls.filter(([p]) => p === '/exams/7/questions').length).toBe(readsBefore + 1)
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('ลบตัวเลือกแล้ว')
  })

  it('deleting an exam QUESTION asks first', async () => {
    server.exams = [EXAM]
    server.examQuestions = [{ id: 70, exam_id: 7, question_text: 'ข้อหนึ่ง', sort_order: 0, options: [] }]
    const wrapper = await openExamsTab()
    await buttonWithText(wrapper, 'จัดการคำถาม').trigger('click')
    await flushPromises()

    await wrapper.find('[data-test="delete-exam-question"]').trigger('click')
    await flushPromises()
    expect(del).not.toHaveBeenCalled()

    del.mockResolvedValueOnce(undefined)
    await confirmOpenDialog(wrapper)
    expect(del).toHaveBeenCalledWith('/exam-questions/70')
    expect(saveFeedbackState.body).toBe('ลบคำถามแล้ว')
  })
})

describe('AcademyManagementView — ADR-052 completion override, reorder and manual grant', () => {
  it('completion override re-reads the learner progress panel before saying it saved', async () => {
    server.modules = [
      makeModule({ lessons: [makeLesson({ source_type: 'upload', content_ref: null, duration_seconds: 600 })] }),
    ]
    server.lessonProgress = [
      {
        id: 1,
        user_id: 42,
        user: { id: 42, first_name: 'สมชาย', last_name: 'ใจดี' },
        last_position_seconds: 100,
        max_position_seconds: 120,
        last_page: null,
        max_page: null,
        total_pages: null,
        updated_at: '2026-10-01T00:00:00Z',
      },
    ]
    const wrapper = await mountView()
    await buttonWithText(wrapper, 'จัดการบทเรียน').trigger('click')
    await flushPromises()
    await buttonWithText(wrapper, 'ความคืบหน้าผู้เรียน').trigger('click')
    await flushPromises()

    const progressReads = () => get.mock.calls.filter(([p]) => p === '/module-lessons/501/progress').length
    expect(progressReads()).toBe(1)

    await buttonWithText(wrapper, 'ทำเครื่องหมายว่าเรียนจบให้').trigger('click')
    post.mockResolvedValueOnce({ data: { id: 9, user_id: 42, module_lesson: { title: 'วิดีโอแนะนำแพ็กเกจ' } } })
    await confirmOpenDialog(wrapper)

    expect(post).toHaveBeenCalledWith('/module-lessons/501/completions/override', { user_id: 42 })
    // LOCAL→SERVER: the panel used to stay as it was; it is re-read now.
    expect(progressReads()).toBe(2)
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('บันทึกว่า สมชาย ใจดี เรียนจบบทเรียน “วิดีโอแนะนำแพ็กเกจ” แล้ว')
  })

  it('dragging a lesson raises the dialog after the reorder PUT resolved; a failed one raises none', async () => {
    stubMatchMedia(true)
    const a = makeLesson({ id: 501, title: 'บทเอ', sort_order: 0 })
    const b = makeLesson({ id: 502, title: 'บทบี', sort_order: 1 })
    server.modules = [makeModule({ lessons: [a, b] })]
    const wrapper = await mountView()

    async function drag() {
      const rows = wrapper.findAll('[role="button"]')
      const rowA = rows.find((n) => n.text().includes('บทเอ'))!
      const rowB = rows.find((n) => n.text().includes('บทบี'))!
      await rowA.find('span[aria-hidden="true"]').trigger('mousedown')
      await rowA.trigger('dragstart')
      await rowB.trigger('drop')
    }

    const write = deferred<unknown>()
    put.mockReturnValueOnce(write.promise)
    await drag()
    await flushPromises()
    expect(put).toHaveBeenCalledWith('/modules/301/lessons/reorder', { lesson_ids: [502, 501] })
    expect(saveFeedbackState.show).toBe(false)

    write.resolve({ data: [{ ...b, sort_order: 0 }, { ...a, sort_order: 1 }] })
    await flushPromises()
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('จัดลำดับบทเรียนแล้ว')

    // A failing reorder: restored order, the existing amber notice, no dialog.
    const countBefore = saveFeedbackState.count
    put.mockRejectedValueOnce(new FakeApiError(422, 'กรุณารีเฟรชหน้าแล้วลองใหม่'))
    await drag()
    await flushPromises()
    expect(saveFeedbackState.count).toBe(countBefore)
    expect(wrapper.text()).toContain('จัดลำดับบทเรียนไม่สำเร็จ — คืนลำดับเดิมแล้ว')
  })

  it('manual grant: dialog names the tier the SERVER granted, after the progress roster was re-read', async () => {
    server.progressRows = [
      {
        user_id: 42,
        name: 'สมชาย ใจดี',
        first_name: 'สมชาย',
        last_name: 'ใจดี',
        required_lesson_count: 0,
        completed_required_count: 0,
        completed_optional_count: 0,
        completed_lesson_ids: [],
        cert_tiers_passed: [],
        sections: [],
      },
    ]
    const wrapper = await mountView()
    await buttonWithText(wrapper, 'ความคืบหน้าสมาชิก', true).trigger('click')
    await flushPromises()
    await wrapper.findAll('div.cursor-pointer').find((d) => d.text().includes('สมชาย'))!.trigger('click')
    await flushPromises()

    await buttonWithText(wrapper, '+ อนุมัติ Basic').trigger('click')
    post.mockImplementationOnce(() => {
      server.progressRows = [
        {
          ...(server.progressRows[0] as object),
          cert_tiers_passed: [{ id: 1, key: 'basic', name: 'Basic', passed_at: '2026-10-01' }],
        },
      ]

      return Promise.resolve({ data: { id: 1, user_id: 42, cert_tier: { id: 1, key: 'basic', name: 'Basic (ระดับพื้นฐาน)' } } })
    })
    await confirmOpenDialog(wrapper)

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('อนุมัติใบรับรอง “Basic (ระดับพื้นฐาน)” แล้ว')
    // The roster on screen is the re-read one: the tier is no longer offered.
    expect(wrapper.text()).not.toContain('+ อนุมัติ Basic')
  })
})
