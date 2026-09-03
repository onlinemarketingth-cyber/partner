/**
 * The identity document, at the place it is now asked for.
 *
 * ── WHY THIS FILE EXISTS ──
 *
 * Until 2026-08-28 the national ID was typed on the SIGNUP form, and its
 * contract was pinned by RegisterViewNationalId.spec.ts. Commit 632e1fd moved
 * the field here — a recruit is asked for it when there is a payout to make,
 * not before they have an account — and the field's tests were left behind
 * asserting a control that no longer existed on that page. They failed from
 * that day until 2026-09-03, and while they were red nothing at all covered
 * the behaviour at its new home.
 *
 * So this is the moved half, not new scope: the two documents share one
 * column and must never share a value, and only a Thai card gets the boxes.
 *
 * NationalIdSegments.spec.ts still owns the typing behaviour (paste, digits
 * only, thirteen maximum, no checksum in the browser). What is asserted HERE
 * is only what this page decides.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const put = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn().mockResolvedValue({ data: {} }),
    post: vi.fn(),
    put: (...args: unknown[]) => put(...args),
    patch: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: class extends Error {},
  ensureCsrfCookie: vi.fn().mockResolvedValue(undefined),
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  useRoute: () => ({ name: 'profile-settings', params: {}, query: {} }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import ProfileSettingsView from '../ProfileSettingsView.vue'
import { useAuthStore, type AuthUser } from '@/stores/auth'

const ME = {
  id: 7,
  name: 'สมหญิง ทดสอบ',
  email: 'somying@example.com',
  role: 'agent',
  national_id: null,
  id_document_type: null,
} as unknown as AuthUser

async function mountProfile() {
  const auth = useAuthStore()
  auth.setUser(ME)

  const wrapper = mount(ProfileSettingsView, {
    global: { stubs: { Icon: true, HeroHeader: true, Teleport: true } },
  })
  await flushPromises()

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountProfile>>

function typeButton(wrapper: Wrapper, label: string) {
  const button = wrapper.findAll('button').find((b) => b.text().includes(label))
  if (!button) throw new Error(`no "${label}" button on the profile page`)

  return button
}

beforeEach(() => {
  setActivePinia(createPinia())
  put.mockReset()
  put.mockResolvedValue({ data: ME })
})

describe('ProfileSettingsView — the identity document', () => {
  it('gives a Thai card the five groups it prints, not one long box', async () => {
    const wrapper = await mountProfile()

    expect(wrapper.find('[role="group"]').exists()).toBe(true)
    expect(
      wrapper.findAll('[role="group"] input').map((b) => Number(b.attributes('maxlength'))),
    ).toEqual([1, 4, 5, 2, 1])
  })

  it('gives a passport ONE field, because a passport number has no printed groups', async () => {
    const wrapper = await mountProfile()

    await typeButton(wrapper, 'หนังสือเดินทาง').trigger('click')

    expect(wrapper.find('[role="group"]').exists()).toBe(false)
    expect(wrapper.find('input#profile_national_id').exists()).toBe(true)
  })

  it('sends thirteen bare digits when the card is typed with its printed dashes', async () => {
    // What somebody holding the card actually does. Before the boxes existed,
    // the separators ate four of the thirteen slots and the server answered
    // "must be 13 digits" to a person who had typed thirteen.
    const wrapper = await mountProfile()

    await wrapper.findAll('[role="group"] input')[0]!.setValue('1-1017-00230-70-8')
    await typeButton(wrapper, 'บันทึกเอกสารยืนยันตัวตน').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/me/id-document', {
      id_document_type: 'thai_national_id',
      national_id: '1101700230708',
    })
  })

  it('does not judge the checksum in the browser — that is the server\'s one job here', async () => {
    // 1101700230700 fails mod-11 and must still be typeable AND sendable. If
    // this ever fails, a checksum has been copied into Vue and the two
    // implementations have started drifting.
    const wrapper = await mountProfile()

    await wrapper.findAll('[role="group"] input')[0]!.setValue('1101700230700')
    await typeButton(wrapper, 'บันทึกเอกสารยืนยันตัวตน').trigger('click')
    await flushPromises()

    expect(put.mock.calls[0]?.[1]).toMatchObject({ national_id: '1101700230700' })
  })

  it('clears the number when the document type changes', async () => {
    // A 13-digit Thai ID submitted under a "passport" label is a guaranteed
    // 422, and a confusing one. One column, two documents, never one value.
    const wrapper = await mountProfile()

    await wrapper.findAll('[role="group"] input')[0]!.setValue('1101700230708')
    await typeButton(wrapper, 'หนังสือเดินทาง').trigger('click')

    expect((wrapper.find('input#profile_national_id').element as HTMLInputElement).value).toBe('')
  })

  it('does not strip anything from a passport number', async () => {
    const wrapper = await mountProfile()
    await typeButton(wrapper, 'หนังสือเดินทาง').trigger('click')

    await wrapper.find('input#profile_national_id').setValue('AB1234567')
    await typeButton(wrapper, 'บันทึกเอกสารยืนยันตัวตน').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/me/id-document', {
      id_document_type: 'passport',
      national_id: 'AB1234567',
    })
  })
})
