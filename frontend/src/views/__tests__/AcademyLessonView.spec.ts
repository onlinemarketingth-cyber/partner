/**
 * TASK-167 §4.1 + TASK-165 — the two things that break SILENTLY when the
 * lesson content moves to its own screen.
 *
 *  1. PROGRESS REPORTING. TASK-165's automatic completion is driven entirely
 *     by `PUT /module-lessons/{id}/progress` and its `{completed: …}` reply.
 *     If that wiring is lost nothing fails loudly — no error, no red test,
 *     just learners who never finish a lesson, on the BR-1 certification
 *     path. So it is asserted here rather than eyeballed.
 *
 *  2. §4.1's HAND-OFF, as AMENDED (rev.2, 2026-08-11). The gate trips at the
 *     configured threshold — 80% by default — NOT at the end, so meeting it
 *     must NOT navigate: doing so threw learners out of a still-playing
 *     video. Completion is recorded and the next step is offered; only the
 *     video's `ended` event moves anyone.
 *
 *     Both halves are asserted, because each fails silently on its own: a
 *     navigation on completion is invisible in a build, and a missing
 *     `ended` hand-off just looks like a learner who stopped.
 *
 *     "Never navigate to a lesson the server would refuse" still holds — the
 *     second test's next sibling is LOCKED and must be skipped.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type { ModuleLessonItem } from '@/utils/academy'

const replace = vi.fn()
const push = vi.fn()

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '1' } }),
  useRouter: () => ({ replace, push }),
}))

const get = vi.fn()
const put = vi.fn()
const post = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    put: (...args: unknown[]) => put(...args),
    post: (...args: unknown[]) => post(...args),
    downloadAbsolute: vi.fn(),
  },
}))

import AcademyLessonView from '../AcademyLessonView.vue'
import LessonVideoPlayer from '@/design-system/components/LessonVideoPlayer.vue'

function lessonFixture(overrides: Partial<ModuleLessonItem> = {}): ModuleLessonItem {
  return {
    id: 1,
    module_id: 10,
    title: 'บทเรียนวิดีโอ',
    content_type: 'video',
    source_type: 'upload',
    content_ref: null,
    stream_url: 'http://api.test/api/v1/module-lessons/1/stream',
    inline_url: 'http://api.test/api/v1/module-lessons/1/stream',
    is_downloadable: false,
    duration_seconds: 600,
    page_count: null,
    processing_status: 'ready',
    xp_reward: 50,
    is_published: true,
    completion_is_automatic: true,
    quiz_question_count: 0,
    quiz_unlocked: false,
    quiz_blocks_completion: false,
    quiz_passed: null,
    is_optional: false,
    is_locked: false,
    lock_reason: null,
    lock_message: null,
    unlocks_at: null,
    ...overrides,
  }
}

const stubs = {
  HeroHeader: true,
  LoadingSkeleton: true,
  PdfViewerModal: true,
  AuthenticatedMedia: true,
  RouterLink: true,
  Icon: true,
}

describe('AcademyLessonView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('records completion without navigating, then hands off to the quiz when the video ends', async () => {
    const lesson = lessonFixture({ quiz_question_count: 3, quiz_unlocked: true })
    get.mockImplementation((path: string) => {
      if (path === '/module-lessons/1') return Promise.resolve({ data: lesson })
      if (path === '/me/module-lessons/1/progress')
        return Promise.resolve({ last_position_seconds: null, last_page: null })
      return Promise.resolve({ data: [] })
    })
    // TASK-165 §3.2 — the server records the completion off this very ping.
    put.mockResolvedValue({ completed: true })

    const wrapper = mount(AcademyLessonView, { global: { stubs } })
    await flushPromises()

    wrapper.findComponent(LessonVideoPlayer).vm.$emit('position', 42)
    await flushPromises()

    // RAW POSITION ONLY — no percentage, no `completed` asserted by the
    // client (ADR-028 §3).
    expect(put).toHaveBeenCalledWith('/module-lessons/1/progress', { last_position_seconds: 42 })

    // §4.1 rev.2 — the gate is met, so the completion is recorded and the
    // next step is OFFERED. Nothing moves: the video may still be playing.
    expect(replace).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('เรียนจบแล้ว')
    expect(wrapper.text()).toContain('ทำแบบทดสอบท้ายบทเรียน')

    // The video actually ends — now there is nothing left to interrupt.
    wrapper.findComponent(LessonVideoPlayer).vm.$emit('ended')
    await flushPromises()

    expect(replace).toHaveBeenCalledWith('/academy/lessons/1/quiz')
  })

  it('skips a locked next lesson when the finished lesson has no quiz (§4.1)', async () => {
    const lesson = lessonFixture()
    get.mockImplementation((path: string) => {
      if (path === '/module-lessons/1') return Promise.resolve({ data: lesson })
      if (path === '/me/module-lessons/1/progress')
        return Promise.resolve({ last_position_seconds: null, last_page: null })
      if (path === '/modules')
        return Promise.resolve({
          data: [
            {
              id: 10,
              cert_tier: null,
              product: null,
              title: 'Section',
              enforce_sequential: true,
              drip_days: null,
              unlocks_at: null,
              lesson_count: 3,
              required_lesson_count: 3,
              optional_lesson_count: 0,
              lessons: [
                lesson,
                // Locked — the server would refuse it, so it must not be
                // the destination.
                lessonFixture({ id: 2, is_locked: true, lock_reason: 'drip' }),
                lessonFixture({ id: 3 }),
              ],
            },
          ],
        })
      if (path === '/module-completions')
        return Promise.resolve({ data: [{ id: 99, module_lesson: { id: 1, module_id: 10 } }] })
      return Promise.resolve({ data: [] })
    })
    put.mockResolvedValue({ completed: true })

    const wrapper = mount(AcademyLessonView, { global: { stubs } })
    await flushPromises()

    wrapper.findComponent(LessonVideoPlayer).vm.$emit('position', 120)
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/module-lessons/1/progress', { last_position_seconds: 120 })

    // Still no jump on the gate alone (§4.1 rev.2); this lesson has no quiz,
    // so the offer is the next lesson.
    expect(replace).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('ไปบทถัดไป')

    wrapper.findComponent(LessonVideoPlayer).vm.$emit('ended')
    await flushPromises()

    // Lesson 2 is locked — the server would refuse it, so 3 is the target.
    expect(replace).toHaveBeenCalledWith('/academy/lessons/3')
  })
})
