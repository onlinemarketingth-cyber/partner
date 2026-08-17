/**
 * AppSelect — TASK-173 Phase 1.
 *
 * WHAT BREAKS SILENTLY IF THESE ASSERTIONS ARE LOST
 *
 *  1. THE KEYBOARD STOPS WORKING AND NOTHING ERRORS. This component replaces
 *     a native <select>, which every browser drives from the keyboard for
 *     free. A custom control that only responds to taps still looks perfect
 *     in a screenshot and is a straight DOWNGRADE for anyone using a
 *     keyboard, a switch, or a screen reader — the exact way "fix the
 *     styling" turns into a regression. Arrow/Home/End/Enter/Esc/type-ahead
 *     are asserted individually because each is a separate branch.
 *
 *  2. FOCUS GOES TO <body> ON CLOSE. When the list closes, focus must be
 *     back on the trigger — otherwise the next Tab restarts from the top of
 *     the document and the user loses their place with no visible cause.
 *     Both close paths (Esc, and selecting) are asserted, because a fix
 *     applied to one is easy to forget on the other.
 *
 *  3. ESC SILENTLY CHANGES THE VALUE. Esc means "cancel". If the highlight
 *     leaked into the model, arrowing past an option and pressing Esc would
 *     commit it — a wrong client status or category written by a keypress
 *     that means the opposite.
 *
 *  4. THE 77-PROVINCE LIST BECOMES SCROLL-ONLY. Type-ahead is what makes a
 *     long list usable at all; without it the last province is a long drag
 *     away, which is a different flavour of the same "cannot be used on a
 *     phone" complaint TASK-173 exists to fix. Asserted against the REAL
 *     THAILAND_PROVINCES constant and its genuinely LAST entry, so it cannot
 *     pass on a convenient three-item fixture.
 *
 *  5. THE SUBMITTED PAYLOAD CHANGES. Every TASK-173 conversion is
 *     presentation-only. The DOM stringifies everything; this component must
 *     not, or a numeric id would start arriving at the API as "3". The
 *     emitted value is asserted with toBe() AND typeof for that reason.
 *
 *  6. A DISABLED CONTROL BECOMES CLICKABLE. Two ClientsView selects are
 *     disabled while a write is in flight; if that stops holding, a second
 *     PUT races the first.
 *
 * The overlay is FilterSheet (deliberately reused, not re-implemented), so
 * the list is TELEPORTED to document.body — it is queried through the
 * document here, not through the wrapper, which is why every mount uses
 * `attachTo` and every test unmounts.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import AppSelect from '../AppSelect.vue'
import FilterSheet from '../FilterSheet.vue'
import { THAILAND_PROVINCES } from '@/design-system/constants/thailandProvinces'

type Mounted = ReturnType<typeof mount>

let mounted: Mounted | null = null

afterEach(() => {
  mounted?.unmount()
  mounted = null
  document.body.innerHTML = ''
  document.body.style.overflow = ''
})

interface Opt {
  value: string | number | null
  label: string
  disabled?: boolean
}

function build(props: Record<string, unknown>) {
  const wrapper = mount(AppSelect as never, {
    attachTo: document.body,
    // AppSelect is `generic="T">`, so VTU's mount() cannot infer a props type
    // for it here; the shapes are asserted by the tests themselves.
    props: props as never,
  })
  mounted = wrapper
  return wrapper
}

const trigger = (w: Mounted) => w.get('[role="combobox"]')

function listbox(): HTMLElement {
  const el = document.querySelector<HTMLElement>('[role="listbox"]')
  if (!el) throw new Error('the listbox is not open')
  return el
}

function optionEls(): HTMLElement[] {
  return Array.from(listbox().querySelectorAll<HTMLElement>('[role="option"]'))
}

/** The option the roving highlight is on, read the way a screen reader does. */
function activeOptionLabel(): string {
  const id = listbox().getAttribute('aria-activedescendant')
  if (!id) throw new Error('no aria-activedescendant on the listbox')
  const el = document.getElementById(id)
  if (!el) throw new Error(`aria-activedescendant points at #${id}, which does not exist`)
  return el.textContent?.trim() ?? ''
}

/** Real keydown on the open listbox — it is teleported, so VTU cannot reach it. */
async function press(key: string) {
  listbox().dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }))
  await nextTick()
}

const FRUIT: Opt[] = [
  { value: 'apple', label: 'Apple' },
  { value: 'banana', label: 'Banana' },
  { value: 'cherry', label: 'Cherry' },
]

const PROVINCE_OPTIONS: Opt[] = THAILAND_PROVINCES.map((p) => ({ value: p, label: p }))

describe('AppSelect — trigger and ARIA wiring', () => {
  it('exposes a combobox trigger that reports its own open state', async () => {
    const w = build({ modelValue: 'apple', options: FRUIT })
    const t = trigger(w)

    expect(t.attributes('aria-haspopup')).toBe('listbox')
    expect(t.attributes('aria-expanded')).toBe('false')
    expect(t.text()).toContain('Apple')

    await t.trigger('click')

    expect(trigger(w).attributes('aria-expanded')).toBe('true')
    // aria-controls must point at the list that actually opened, not at a
    // plausible-looking id — a mismatch is invisible except to AT.
    expect(t.attributes('aria-controls')).toBe(listbox().id)
  })

  it('keeps a 44px minimum tap target (TASK-087)', () => {
    const w = build({ modelValue: null, options: FRUIT })
    expect(trigger(w).classes()).toContain('min-h-[44px]')
  })

  it('merges a call-site class onto the trigger instead of dropping it', () => {
    // The component has two roots (trigger + the teleported sheet), so
    // `inheritAttrs` is off and attrs are routed by hand. If that routing
    // breaks, every converted call site silently loses its layout classes —
    // e.g. the `mt-1` that spaces these controls under their labels in the
    // ClientsView drawer.
    const w = build({ modelValue: null, options: FRUIT, class: 'mt-1' })
    const classes = trigger(w).classes()

    expect(classes).toContain('mt-1')
    expect(classes).toContain('min-h-[44px]')
  })

  it('shows the placeholder, in placeholder ink, when no option matches', () => {
    const w = build({ modelValue: '', options: FRUIT, placeholder: 'เลือกสินค้า' })
    const label = trigger(w).get('span')

    expect(label.text()).toBe('เลือกสินค้า')
    expect(label.classes()).toContain('text-ink-input-placeholder')
  })

  it('marks the selected option, and only that one, aria-selected', async () => {
    const w = build({ modelValue: 'banana', options: FRUIT })
    await trigger(w).trigger('click')

    expect(optionEls().map((o) => o.getAttribute('aria-selected'))).toEqual([
      'false',
      'true',
      'false',
    ])
  })

  it('renders its list inside FilterSheet rather than a second sheet of its own', async () => {
    const w = build({ modelValue: null, options: FRUIT })
    await trigger(w).trigger('click')

    const sheet = w.findComponent(FilterSheet)
    expect(sheet.exists()).toBe(true)
    expect(sheet.props('open')).toBe(true)
    // The listbox is inside the sheet's scroller, so a long list scrolls in
    // the panel instead of running off the screen.
    expect(listbox().closest('.overflow-y-auto')).not.toBeNull()
  })
})

describe('AppSelect — keyboard operation', () => {
  it('opens on ArrowDown from the trigger and starts on the selected option', async () => {
    const w = build({ modelValue: 'banana', options: FRUIT })
    await trigger(w).trigger('keydown', { key: 'ArrowDown' })

    expect(activeOptionLabel()).toBe('Banana')
  })

  it('opens on Enter and on Space too', async () => {
    for (const key of ['Enter', ' ']) {
      const w = build({ modelValue: null, options: FRUIT })
      await trigger(w).trigger('keydown', { key })
      expect(listbox()).toBeTruthy()
      w.unmount()
      document.body.innerHTML = ''
    }
    mounted = null
  })

  it('moves the highlight with the arrows, Home and End', async () => {
    const w = build({ modelValue: 'apple', options: FRUIT })
    await trigger(w).trigger('click')

    expect(activeOptionLabel()).toBe('Apple')
    await press('ArrowDown')
    expect(activeOptionLabel()).toBe('Banana')
    await press('ArrowDown')
    expect(activeOptionLabel()).toBe('Cherry')
    // Clamps at the end rather than wrapping, like a native list.
    await press('ArrowDown')
    expect(activeOptionLabel()).toBe('Cherry')
    await press('ArrowUp')
    expect(activeOptionLabel()).toBe('Banana')
    await press('Home')
    expect(activeOptionLabel()).toBe('Apple')
    await press('End')
    expect(activeOptionLabel()).toBe('Cherry')
  })

  it('commits the highlighted option on Enter and returns focus to the trigger', async () => {
    const w = build({ modelValue: 'apple', options: FRUIT })
    await trigger(w).trigger('click')
    await press('ArrowDown')
    await press('Enter')
    await nextTick()

    expect(w.emitted('update:modelValue')).toEqual([['banana']])
    expect(document.querySelector('[role="listbox"]')).toBeNull()
    expect(document.activeElement).toBe(trigger(w).element)
  })

  it('commits on Space as well', async () => {
    const w = build({ modelValue: 'apple', options: FRUIT })
    await trigger(w).trigger('click')
    await press('ArrowDown')
    await press(' ')

    expect(w.emitted('update:modelValue')).toEqual([['banana']])
  })

  it('Esc closes, emits NOTHING, and puts focus back on the trigger', async () => {
    const w = build({ modelValue: 'apple', options: FRUIT })
    await trigger(w).trigger('click')
    // Move the highlight first: if Esc leaked the highlight into the model
    // this is where a wrong value would be written.
    await press('ArrowDown')
    await press('ArrowDown')
    await press('Escape')
    await nextTick()

    expect(w.emitted('update:modelValue')).toBeUndefined()
    expect(document.querySelector('[role="listbox"]')).toBeNull()
    expect(document.activeElement).toBe(trigger(w).element)
  })

  it('stops Esc from also reaching the drawer or modal behind it', async () => {
    const w = build({ modelValue: 'apple', options: FRUIT })
    await trigger(w).trigger('click')

    const onBodyEsc = vi.fn()
    document.body.addEventListener('keydown', onBodyEsc)
    await press('Escape')
    document.body.removeEventListener('keydown', onBodyEsc)

    expect(onBodyEsc).not.toHaveBeenCalled()
  })

  it('closes on Tab without committing', async () => {
    const w = build({ modelValue: 'apple', options: FRUIT })
    await trigger(w).trigger('click')
    await press('ArrowDown')
    await press('Tab')
    await nextTick()

    expect(w.emitted('update:modelValue')).toBeUndefined()
    expect(document.querySelector('[role="listbox"]')).toBeNull()
  })
})

describe('AppSelect — type-ahead over a long list', () => {
  it('jumps to a province by typing, and reaches the LAST of the 77', async () => {
    const last = THAILAND_PROVINCES[THAILAND_PROVINCES.length - 1]!
    expect(THAILAND_PROVINCES).toHaveLength(77)

    const w = build({ modelValue: '', options: PROVINCE_OPTIONS, placeholder: 'จังหวัด' })
    await trigger(w).trigger('click')

    for (const ch of [...last]) await press(ch)

    expect(activeOptionLabel()).toBe(last)

    await press('Enter')
    expect(w.emitted('update:modelValue')).toEqual([[last]])
  })

  it('cycles through matches when the SAME character is repeated', async () => {
    const w = build({
      modelValue: null,
      options: [
        { value: 'a1', label: 'Alpha' },
        { value: 'b1', label: 'Bravo' },
        { value: 'a2', label: 'Anchor' },
      ] satisfies Opt[],
    })
    await trigger(w).trigger('click')

    // It opens highlighting Alpha (nothing selected → first option), and the
    // APG rule is that a repeated character searches AFTER the current
    // highlight — otherwise pressing the key again would keep re-finding the
    // option you are already on and the list would look frozen.
    expect(activeOptionLabel()).toBe('Alpha')
    await press('a')
    expect(activeOptionLabel()).toBe('Anchor')
    // Wraps back rather than dead-ending on the last match.
    await press('a')
    expect(activeOptionLabel()).toBe('Alpha')
    await press('a')
    expect(activeOptionLabel()).toBe('Anchor')
  })

  it('opens from the trigger when the user just starts typing', async () => {
    const w = build({ modelValue: '', options: PROVINCE_OPTIONS })
    await trigger(w).trigger('keydown', { key: 'ต' })
    await nextTick()

    expect(activeOptionLabel().startsWith('ต')).toBe(true)
  })
})

describe('AppSelect — value semantics are the native ones', () => {
  it('emits a NUMBER option value as a number, not a stringified one', async () => {
    const w = build({
      modelValue: null,
      options: [
        { value: 1, label: 'หนึ่ง' },
        { value: 2, label: 'สอง' },
      ] satisfies Opt[],
    })
    await trigger(w).trigger('click')
    optionEls()[1]!.click()
    await nextTick()

    const emitted = w.emitted('update:modelValue')!
    expect(emitted).toEqual([[2]])
    expect(typeof emitted[0]![0]).toBe('number')
  })

  it('emits the empty-string option, which is how a form clears a field', async () => {
    const w = build({
      modelValue: 'apple',
      options: [{ value: '', label: 'ไม่ระบุ' }, ...FRUIT] satisfies Opt[],
    })
    await trigger(w).trigger('click')
    optionEls()[0]!.click()
    await nextTick()

    expect(w.emitted('update:modelValue')).toEqual([['']])
  })

  it('emits once per choice — a click is not also a keyboard commit', async () => {
    const w = build({ modelValue: null, options: FRUIT })
    await trigger(w).trigger('click')
    optionEls()[2]!.click()
    await nextTick()

    expect(w.emitted('update:modelValue')).toHaveLength(1)
  })
})

describe('AppSelect — disabled', () => {
  it('cannot be opened while disabled, by click or by key', async () => {
    const w = build({ modelValue: 'apple', options: FRUIT, disabled: true })
    const el = trigger(w).element as HTMLButtonElement

    expect(el.disabled).toBe(true)

    // Two separate protections, and the test has to exercise both. VTU's
    // `trigger()` refuses to fire on a disabled element, so going through it
    // alone would pass even with the component's own guard deleted — it
    // would only be asserting that the attribute is present. The raw
    // dispatches below DO reach the handlers, which is what proves the JS
    // guard is there too (a `pointer-events`/CSS-only "disabled" is a real
    // way this leaks).
    await trigger(w).trigger('click')
    el.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
    el.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true }),
    )
    await nextTick()

    expect(document.querySelector('[role="listbox"]')).toBeNull()
    expect(w.emitted('update:modelValue')).toBeUndefined()
  })

  it('skips a disabled OPTION on the arrows and refuses to commit it', async () => {
    const w = build({
      modelValue: null,
      options: [
        { value: '', label: 'เลือกสินค้า', disabled: true },
        { value: 'a', label: 'Alpha' },
        { value: 'b', label: 'Bravo' },
      ] satisfies Opt[],
    })
    await trigger(w).trigger('click')

    // Opens on the first ENABLED option, never the disabled prompt.
    expect(activeOptionLabel()).toBe('Alpha')

    optionEls()[0]!.click()
    await nextTick()
    expect(w.emitted('update:modelValue')).toBeUndefined()

    await press('ArrowUp')
    expect(activeOptionLabel()).toBe('Alpha')
  })
})
