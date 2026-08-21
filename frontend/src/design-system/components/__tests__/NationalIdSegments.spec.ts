/**
 * The Thai national ID field: five boxes matching the card, advancing on
 * their own.
 *
 * ── WHAT THESE ASSERTIONS ARE PROTECTING ──
 *
 * 1. THE VALUE THAT LEAVES IS ALWAYS BARE DIGITS. App\Rules\ThaiNationalId
 *    demands digits-only because the stored value feeds a blind index used
 *    for per-company duplicate detection — one number must produce one hash.
 *    A component that let a dash through would be rejected by the server
 *    with a message about a format the person cannot see they typed.
 *
 * 2. AUTO-ADVANCE WITHOUT AUTO-RETREAT IS A TRAP. Boxes that only ever move
 *    forward turn "fix one digit" into "clear it all and start again". The
 *    backspace and arrow-key tests are not polish; they are the difference
 *    between a helpful field and an infuriating one.
 *
 * 3. A PASTE MUST NOT BE SILENTLY TRUNCATED. That was the ORIGINAL bug this
 *    whole line of work started from (2026-08-21, /j/aN3tDZqGjR): a
 *    formatted number lost its tail with no keystroke to notice it by. If a
 *    paste into box three ever fills only box three again, the bug is back
 *    wearing a new shape.
 *
 * 4. NO CHECKSUM IN THE BROWSER, EVER. The mod-11 rule has exactly one
 *    implementation, server-side, next to the hash that depends on it. The
 *    last test argues with a future "let's validate it here too" — the two
 *    copies drift, and the drift is invisible until somebody is told their
 *    own ID card is invalid.
 */
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import NationalIdSegments from '../NationalIdSegments.vue'

/** Renders with a working v-model, the way the form uses it. */
function mountField(initial = '') {
  const wrapper = mount(NationalIdSegments, {
    props: {
      modelValue: initial,
      'onUpdate:modelValue': (value: string) => wrapper.setProps({ modelValue: value }),
    },
    attachTo: document.body,
  })

  return wrapper
}

/** The value the form would submit. */
function currentValue(wrapper: ReturnType<typeof mountField>): string {
  const emitted = wrapper.emitted('update:modelValue')

  return emitted ? String(emitted[emitted.length - 1]?.[0] ?? '') : String(wrapper.props('modelValue'))
}

function boxes(wrapper: ReturnType<typeof mountField>) {
  return wrapper.findAll('input')
}

describe('NationalIdSegments', () => {
  it('splits the number into the five groups the card prints', () => {
    const wrapper = mountField('1101700230708')

    expect(boxes(wrapper).map((b) => (b.element as HTMLInputElement).value)).toEqual([
      '1',
      '1017',
      '00230',
      '70',
      '8',
    ])
  })

  it('moves to the next box on its own once a group is full', async () => {
    const wrapper = mountField()

    await boxes(wrapper)[0]!.setValue('1')

    expect(document.activeElement).toBe(boxes(wrapper)[1]!.element)
  })

  it('accepts the whole number typed into the first box without pausing', async () => {
    // What actually happens on a phone: thirteen digits, no pauses, no taps.
    const wrapper = mountField()

    await boxes(wrapper)[0]!.setValue('1101700230708')

    expect(currentValue(wrapper)).toBe('1101700230708')
  })

  it('fills the whole number from a paste into ANY box', async () => {
    // Somebody pasting their ID into the third group means "here is my ID",
    // never "here are groups three onward".
    const wrapper = mountField()
    const paste = new Event('paste') as ClipboardEvent
    Object.defineProperty(paste, 'clipboardData', {
      value: { getData: () => '1-1017-00230-70-8' },
    })

    boxes(wrapper)[2]!.element.dispatchEvent(paste)
    await wrapper.vm.$nextTick()

    expect(currentValue(wrapper)).toBe('1101700230708')
  })

  it('keeps only digits, so the value matches what the server calls canonical', async () => {
    const wrapper = mountField()

    await boxes(wrapper)[1]!.setValue('1a0-1 7')

    expect(currentValue(wrapper)).toBe('1017')
  })

  it('never grows past thirteen digits', async () => {
    const wrapper = mountField()

    await boxes(wrapper)[0]!.setValue('11017002307089999')

    expect(currentValue(wrapper)).toBe('1101700230708')
  })

  it('steps BACK on backspace in an empty box, and deletes there', async () => {
    // Box 1 holds the single leading digit and box 2 is empty. Backspace
    // here has to reach into box 1 — otherwise the keystroke does nothing
    // and the person is stuck in a box they cannot leave by typing.
    const wrapper = mountField('1')
    const second = boxes(wrapper)[1]!

    await second.trigger('keydown', { key: 'Backspace' })

    expect(currentValue(wrapper)).toBe('')
  })

  it('reaches back from the START of a box that is not empty', async () => {
    // Same keystroke, different situation: the caret sits before the first
    // character of a full group. Deleting the previous group's last digit
    // is what a single field would have done, and the groups are only
    // presentation — the digits after it close up, exactly as they would
    // if the whole number lived in one box.
    const wrapper = mountField('11017')
    const second = boxes(wrapper)[1]!
    ;(second.element as HTMLInputElement).setSelectionRange(0, 0)

    await second.trigger('keydown', { key: 'Backspace' })

    expect(currentValue(wrapper)).toBe('1017')
  })

  it('walks left with the arrow key at the start of a box', async () => {
    const wrapper = mountField('11017')
    const second = boxes(wrapper)[1]!
    ;(second.element as HTMLInputElement).setSelectionRange(0, 0)

    await second.trigger('keydown', { key: 'ArrowLeft' })
    await wrapper.vm.$nextTick()

    expect(document.activeElement).toBe(boxes(wrapper)[0]!.element)
  })

  it('announces completion exactly once the thirteenth digit lands', async () => {
    // The form hangs the "is this ID already registered" moment off this,
    // so firing early would ask about a half-typed number.
    const wrapper = mountField('110170023070')

    await boxes(wrapper)[4]!.setValue('8')

    const complete = wrapper.emitted('complete') ?? []
    expect(complete[complete.length - 1]?.[0]).toBe('1101700230708')
  })

  it('stays silent while the number is still incomplete', async () => {
    const wrapper = mountField()

    await boxes(wrapper)[0]!.setValue('1')
    await boxes(wrapper)[1]!.setValue('1017')

    expect(wrapper.emitted('complete')).toBeUndefined()
  })

  it('does not judge the checksum — a wrong check digit is still typeable', async () => {
    // 1101700230700 fails mod-11. The browser must not know or care.
    const wrapper = mountField()

    await boxes(wrapper)[0]!.setValue('1101700230700')

    expect(currentValue(wrapper)).toBe('1101700230700')
    expect(wrapper.text()).not.toContain('เลขตรวจสอบ')
  })
})
