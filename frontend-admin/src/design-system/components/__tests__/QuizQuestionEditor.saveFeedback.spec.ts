/**
 * QuizQuestionEditor — ADR-052, "saved" is said once, and only when true.
 *
 * The editor serves two callers (a lesson's quiz panel and the quiz library),
 * and each mutation already awaited the caller's `reload`. What it never did
 * was SAY anything, and two of its writes — deleting a question (with all its
 * options) and deleting an option — fired straight off a click.
 *
 * `reload` below plays the caller: it swaps in what the "server" now holds,
 * which deliberately differs from what was typed.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { saveFeedbackState, SAVED_BUT_STALE_BODY } from '@/composables/useSaveFeedback'

const put = vi.fn()
const post = vi.fn()
const del = vi.fn()

const { FakeApiError } = vi.hoisted(() => ({
  FakeApiError: class extends Error {
    constructor(
      public status: number,
      message: string,
    ) {
      super(message)
    }
  },
}))

vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn(),
    put: (...args: unknown[]) => put(...args),
    post: (...args: unknown[]) => post(...args),
    delete: (...args: unknown[]) => del(...args),
  },
  ApiError: FakeApiError,
}))

import QuizQuestionEditor from '../QuizQuestionEditor.vue'

const QUESTION = {
  id: 40,
  question_text: 'ข้อใดถูก',
  sort_order: 0,
  options: [{ id: 400, option_text: 'ตัวเลือกเอ', is_correct: false, sort_order: 0 }],
}

type Q = typeof QUESTION
let serverQuestions: Q[] = []
let wrapper: VueWrapper
const reload = vi.fn(async () => {
  await wrapper.setProps({ questions: serverQuestions })
})

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((res) => {
    resolve = res
  })

  return { promise, resolve }
}

function mountEditor(questions: Q[] = [QUESTION]) {
  wrapper = mount(QuizQuestionEditor, {
    props: { questions, addQuestionPath: '/quizzes/9/questions', reload },
    global: { stubs: { Icon: true } },
  })

  return wrapper
}

async function confirmOpenDialog() {
  const dialog = wrapper.findAllComponents({ name: 'ConfirmDialog' }).find((d) => d.props('show') === true)
  expect(dialog, 'an open ConfirmDialog').toBeDefined()
  expect(dialog!.props('variant')).toBe('danger')
  dialog!.vm.$emit('confirm')
  await flushPromises()
}

beforeEach(() => {
  put.mockReset()
  post.mockReset()
  del.mockReset()
  reload.mockClear()
  serverQuestions = [QUESTION]
})

describe('QuizQuestionEditor — ADR-052', () => {
  it('add question: dialog only after the POST resolved AND the reload put the server list on screen', async () => {
    mountEditor([])
    await wrapper.find('input[placeholder="เพิ่มคำถามใหม่..."]').setValue('คำถามที่พิมพ์')

    const write = deferred<unknown>()
    post.mockReturnValueOnce(write.promise)
    await wrapper.findAll('button').find((b) => b.text().includes('+ เพิ่มคำถาม'))!.trigger('click')
    await flushPromises()
    expect(saveFeedbackState.show).toBe(false)
    expect(reload).not.toHaveBeenCalled()

    serverQuestions = [{ ...QUESTION, question_text: 'คำถามที่เซิร์ฟเวอร์เก็บ' }]
    write.resolve({ data: serverQuestions[0] })
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/quizzes/9/questions', { question_text: 'คำถามที่พิมพ์' })
    expect(reload).toHaveBeenCalledTimes(1)
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('เพิ่มคำถามแล้ว')
    expect(wrapper.text()).toContain('คำถามที่เซิร์ฟเวอร์เก็บ')
    expect((wrapper.find('input[placeholder="เพิ่มคำถามใหม่..."]').element as HTMLInputElement).value).toBe('')
  })

  it('a failing add raises NO dialog, shows the error and keeps what was typed', async () => {
    mountEditor([])
    await wrapper.find('input[placeholder="เพิ่มคำถามใหม่..."]').setValue('คำถามที่พิมพ์')

    post.mockRejectedValueOnce(new FakeApiError(422, 'คำถามยาวเกินไป'))
    await wrapper.findAll('button').find((b) => b.text().includes('+ เพิ่มคำถาม'))!.trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(reload).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('คำถามยาวเกินไป')
    expect((wrapper.find('input[placeholder="เพิ่มคำถามใหม่..."]').element as HTMLInputElement).value).toBe('คำถามที่พิมพ์')
  })

  it('delete question asks first (it used to delete on the click), then raises the dialog', async () => {
    mountEditor()

    await wrapper.find('[data-test="quiz-delete-question"]').trigger('click')
    await flushPromises()
    expect(del).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('ลบคำถาม “ข้อใดถูก” พร้อมตัวเลือก 1 ข้อ ยืนยันหรือไม่?')

    serverQuestions = []
    del.mockResolvedValueOnce(undefined)
    await confirmOpenDialog()

    expect(del).toHaveBeenCalledWith('/module-lesson-quiz-questions/40')
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('ลบคำถามแล้ว')
    expect(wrapper.text()).not.toContain('ข้อใดถูก')
  })

  it('delete option asks first too', async () => {
    mountEditor()

    await wrapper.find('[data-test="quiz-delete-option"]').trigger('click')
    await flushPromises()
    expect(del).not.toHaveBeenCalled()

    serverQuestions = [{ ...QUESTION, options: [] }]
    del.mockResolvedValueOnce(undefined)
    await confirmOpenDialog()

    expect(del).toHaveBeenCalledWith('/module-lesson-quiz-options/400')
    expect(saveFeedbackState.body).toBe('ลบตัวเลือกแล้ว')
    expect(wrapper.text()).not.toContain('ตัวเลือกเอ')
  })

  it('mark correct: the dialog quotes the option the SERVER marked, and the mark comes from the reload', async () => {
    mountEditor()

    serverQuestions = [{ ...QUESTION, options: [{ ...QUESTION.options[0]!, option_text: 'ตัวเลือกเอ (เก็บแล้ว)', is_correct: true }] }]
    put.mockResolvedValueOnce({ data: serverQuestions[0]!.options[0] })
    await wrapper.find('button[title="ทำเครื่องหมายว่าถูกต้อง"]').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('ตั้ง “ตัวเลือกเอ (เก็บแล้ว)” เป็นคำตอบที่ถูกต้องแล้ว')
    expect(wrapper.find('button[title="คำตอบที่ถูกต้อง"]').exists()).toBe(true)
  })

  it('add option: a write that landed but whose reload failed says so, instead of a plain "saved"', async () => {
    mountEditor()
    await wrapper.find('input[placeholder="เพิ่มตัวเลือก..."]').setValue('ตัวเลือกบี')

    post.mockResolvedValueOnce({ data: { id: 401, option_text: 'ตัวเลือกบี' } })
    reload.mockRejectedValueOnce(new Error('reload failed'))
    await wrapper.findAll('button').find((b) => b.text().includes('+ เพิ่ม') && !b.text().includes('คำถาม'))!.trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe(SAVED_BUT_STALE_BODY)
  })

  it('add option on success raises "เพิ่มตัวเลือกแล้ว"', async () => {
    mountEditor()
    await wrapper.find('input[placeholder="เพิ่มตัวเลือก..."]').setValue('ตัวเลือกบี')

    serverQuestions = [{ ...QUESTION, options: [...QUESTION.options, { id: 401, option_text: 'ตัวเลือกบี', is_correct: false, sort_order: 1 }] }]
    post.mockResolvedValueOnce({ data: { id: 401, option_text: 'ตัวเลือกบี' } })
    await wrapper.findAll('button').find((b) => b.text().includes('+ เพิ่ม') && !b.text().includes('คำถาม'))!.trigger('click')
    await flushPromises()

    expect(saveFeedbackState.body).toBe('เพิ่มตัวเลือกแล้ว')
    expect(wrapper.text()).toContain('ตัวเลือกบี')
  })
})
