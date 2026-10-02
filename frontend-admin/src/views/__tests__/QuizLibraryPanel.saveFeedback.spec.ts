/**
 * QuizLibraryPanel — ADR-052, "saved" is said once, and only when true.
 *
 * Create, rename and delete used to close the form / swap the row and say
 * nothing. They now raise the shared dialog after the request resolved AND
 * the list was re-read, quoting the title the SERVER stored.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { saveFeedbackState } from '@/composables/useSaveFeedback'

const get = vi.fn()
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
    get: (...args: unknown[]) => get(...args),
    put: (...args: unknown[]) => put(...args),
    post: (...args: unknown[]) => post(...args),
    delete: (...args: unknown[]) => del(...args),
  },
  ApiError: FakeApiError,
}))

import QuizLibraryPanel from '../QuizLibraryPanel.vue'

function makeQuiz(overrides: Record<string, unknown> = {}) {
  return {
    id: 11,
    company_id: 1,
    title: 'ชุดความรู้พื้นฐาน',
    question_count: 0,
    is_attached: false,
    module_lesson: null,
    ...overrides,
  }
}

let serverQuizzes: ReturnType<typeof makeQuiz>[] = []

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((res) => {
    resolve = res
  })

  return { promise, resolve }
}

async function mountPanel() {
  const wrapper = mount(QuizLibraryPanel, {
    props: { isSuperAdmin: false, selectedCompanyId: null },
    global: { stubs: { Icon: true } },
  })
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  post.mockReset()
  del.mockReset()
  serverQuizzes = [makeQuiz()]
  get.mockImplementation(() =>
    Promise.resolve({ data: serverQuizzes, meta: { last_page: 1, total: serverQuizzes.length } }),
  )
})

describe('QuizLibraryPanel — ADR-052', () => {
  it('create: dialog only after the POST resolved, quoting the SERVER title; the list is re-read', async () => {
    const wrapper = await mountPanel()
    await wrapper.findAll('button').find((b) => b.text().includes('+ สร้างแบบทดสอบ'))!.trigger('click')
    await wrapper.find('form input').setValue('ชุดที่พิมพ์')

    const write = deferred<unknown>()
    post.mockReturnValueOnce(write.promise)
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(saveFeedbackState.show).toBe(false)

    const stored = makeQuiz({ id: 12, title: 'ชุดที่เซิร์ฟเวอร์เก็บ' })
    serverQuizzes = [stored, makeQuiz()]
    write.resolve({ data: stored })
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('สร้างแบบทดสอบ “ชุดที่เซิร์ฟเวอร์เก็บ” แล้ว')
    expect(wrapper.text()).toContain('ชุดที่เซิร์ฟเวอร์เก็บ')
    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.emitted('changed')).toHaveLength(1)
  })

  it('a failing rename raises NO dialog, shows the error and keeps the rename box open', async () => {
    const wrapper = await mountPanel()
    await wrapper.find('button[title="เปลี่ยนชื่อ"]').trigger('click')
    await wrapper.find('input[maxlength="255"]').setValue('ชื่อใหม่')

    put.mockRejectedValueOnce(new FakeApiError(422, 'ชื่อนี้ยาวเกินไป'))
    await wrapper.findAll('button').find((b) => b.text().trim() === 'บันทึก')!.trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.text()).toContain('ชื่อนี้ยาวเกินไป')
    expect(wrapper.find('input[maxlength="255"]').exists()).toBe(true)
  })

  it('rename: the dialog and the row both carry the name the server stored', async () => {
    const wrapper = await mountPanel()
    await wrapper.find('button[title="เปลี่ยนชื่อ"]').trigger('click')
    await wrapper.find('input[maxlength="255"]').setValue('  ชื่อใหม่  ')

    put.mockImplementationOnce(() => {
      serverQuizzes = [makeQuiz({ title: 'ชื่อใหม่ (ปรับโดยระบบ)' })]

      return Promise.resolve({ data: serverQuizzes[0] })
    })
    await wrapper.findAll('button').find((b) => b.text().trim() === 'บันทึก')!.trigger('click')
    await flushPromises()

    expect(saveFeedbackState.body).toBe('เปลี่ยนชื่อแบบทดสอบเป็น “ชื่อใหม่ (ปรับโดยระบบ)” แล้ว')
    expect(wrapper.text()).toContain('ชื่อใหม่ (ปรับโดยระบบ)')
  })

  it('delete asks first, then raises the dialog over the re-read list', async () => {
    const wrapper = await mountPanel()
    await wrapper.find('button[title="ลบแบบทดสอบ"]').trigger('click')
    await flushPromises()
    expect(del).not.toHaveBeenCalled()

    del.mockImplementationOnce(() => {
      serverQuizzes = []

      return Promise.resolve(undefined)
    })
    const dialog = wrapper.findAllComponents({ name: 'ConfirmDialog' }).find((d) => d.props('show') === true)
    expect(dialog).toBeDefined()
    dialog!.vm.$emit('confirm')
    await flushPromises()

    expect(del).toHaveBeenCalledWith('/quizzes/11')
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('ลบแบบทดสอบ “ชุดความรู้พื้นฐาน” แล้ว')
    expect(wrapper.text()).toContain('ยังไม่มีแบบทดสอบท้ายบทเรียน')
  })
})
