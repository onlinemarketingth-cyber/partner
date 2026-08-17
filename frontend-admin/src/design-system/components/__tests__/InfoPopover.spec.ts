/**
 * InfoPopover — TASK-188 Phase A.
 *
 * WHY THIS FILE EXISTS. D1 (human decision, 2026-08-13) puts *every*
 * explanation on the Academy course builder behind this one icon, including
 * the consequence warnings. This component is therefore the only route to the
 * screen's guidance, and each behaviour below is the difference between the
 * guidance existing and not existing for some group of users.
 *
 * What breaks SILENTLY if these assertions are lost — nothing throws, nothing
 * renders red, the screen just quietly stops explaining itself to someone:
 *
 *  1. IT STOPS OPENING ON CLICK. The obvious "fix" for a popover is a
 *     `@mouseenter` handler or the native `title=""` (239 uses in this app).
 *     Both look fine on the developer's laptop and neither opens on a tablet,
 *     which is what this Admin runs on.
 *
 *  2. THE TRIGGER STOPS BEING A BUTTON. A `<div @click>` renders identically
 *     and passes a click test, but it is not in the tab order and neither
 *     Enter nor Space activates it. The `<button type="button">` assertion is
 *     standing in for "Enter/Space open it" — that is the browser behaviour we
 *     are relying on rather than reimplementing (reimplementing it double-fires
 *     on Space and toggles the panel shut again).
 *
 *  3. ESCAPE STOPS CLOSING IT, or closes it and drops focus on <body>. A
 *     keyboard user then has to Tab from the top of the document to get back
 *     to the field they were reading about.
 *
 *  4. THE OUTSIDE-CLICK GUARD IS "SIMPLIFIED". The document listener is
 *     attached from inside the trigger's own click handler, so a naive
 *     `close()` on any document click closes the popover on the very click
 *     that opened it — which presents exactly as "the ⓘ does nothing".
 *     That is why these tests mount with `attachTo: document.body`: detached
 *     mounts never reach `document` and would pass either way.
 *
 *  5. THE CONTENT STOPS BEING ANNOUNCED. Without aria-describedby/-controls
 *     wiring a screen-reader user hears a nameless button and never the text
 *     behind it, and with 32 of these on one screen a generic label is no
 *     better — hence the assertion that the field's own name is in the
 *     accessible name.
 *
 * Positioning is deliberately NOT asserted here: jsdom reports every
 * getBoundingClientRect as 0 and every offsetHeight as 0, so a "does not get
 * clipped" test in jsdom would assert nothing. That requirement is met
 * structurally instead — the panel is teleported out of the builder's
 * `overflow-hidden` cards into <body> — which IS asserted (see the teleport
 * test), and visually in a browser.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import { nextTick } from 'vue'

import InfoPopover from '../InfoPopover.vue'

const TEXT = 'ลบบทเรียนแล้ว ความคืบหน้าของผู้เรียนจะหายไปด้วย'

const wrappers: VueWrapper[] = []

afterEach(() => {
  while (wrappers.length) wrappers.pop()?.unmount()
  document.body.innerHTML = ''
})

/**
 * Attached to document.body on purpose: the outside-click and Escape
 * behaviours are document-level, and a detached mount silently exercises
 * neither.
 */
function mountPopover(props: Record<string, unknown> = {}) {
  const wrapper = mount(InfoPopover, {
    props: { label: 'ลบบทเรียน', text: TEXT, ...props },
    attachTo: document.body,
    global: { stubs: { Icon: true } },
  })
  wrappers.push(wrapper)
  return wrapper
}

/** The teleported panel lives in <body>, not inside the wrapper's own tree. */
function panel(): HTMLElement | null {
  return document.querySelector('[role="tooltip"]')
}

describe('InfoPopover (TASK-188 §3)', () => {
  it('opens on click — the tablet case, not only on hover', async () => {
    const wrapper = mountPopover()

    expect(panel()).toBeNull()

    await wrapper.find('button').trigger('click')

    expect(panel()).not.toBeNull()
    expect(panel()?.textContent).toContain(TEXT)

    // A second press closes it again, so the icon is a toggle rather than a
    // one-way trap.
    await wrapper.find('button').trigger('click')
    expect(panel()).toBeNull()
  })

  it('does NOT rely on hover — the panel stays shut on mouseenter', async () => {
    const wrapper = mountPopover()

    await wrapper.find('button').trigger('mouseenter')
    await nextTick()

    // Hover may decorate, but it must never be the only way in: a hover-only
    // build passes every other test in this file and is unusable on a tablet.
    expect(panel()).toBeNull()
  })

  it('is a real <button>, which is what makes Enter and Space work', () => {
    const wrapper = mountPopover()
    const trigger = wrapper.find('button')

    expect(trigger.exists()).toBe(true)
    expect(trigger.element.tagName).toBe('BUTTON')
    // type="button": inside the builder's <form>s a default-type button
    // submits the form instead of opening the explanation.
    expect(trigger.attributes('type')).toBe('button')
    // Nothing has pulled it out of the tab order.
    expect(trigger.attributes('tabindex')).toBeUndefined()
    expect(trigger.attributes('disabled')).toBeUndefined()
  })

  it('closes on Escape and puts focus back on the trigger', async () => {
    // Focus has to be genuinely INSIDE the panel for this to mean anything:
    // if it never left the trigger, `document.activeElement === trigger`
    // holds whether or not the component restores anything, and the test
    // asserts nothing. So the panel gets a focusable child and we focus it.
    const wrapper = mount(InfoPopover, {
      props: { label: 'ลบบทเรียน' },
      slots: { default: '<a href="#" class="more">อ่านเพิ่ม</a>' },
      attachTo: document.body,
      global: { stubs: { Icon: true } },
    })
    wrappers.push(wrapper)
    const trigger = wrapper.find('button').element as HTMLButtonElement

    trigger.focus()
    await wrapper.find('button').trigger('click')
    expect(panel()).not.toBeNull()

    const link = panel()?.querySelector('a') as HTMLAnchorElement
    link.focus()
    expect(document.activeElement).toBe(link)

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await nextTick()

    expect(panel()).toBeNull()
    // Without the restore, tearing down the panel drops focus on <body> and
    // strands the keyboard user at the top of the document, several dozen
    // Tabs from the field they were reading about.
    expect(document.activeElement).toBe(trigger)
  })

  it('does not steal focus when Escape is pressed after tabbing away', async () => {
    const wrapper = mountPopover()
    const elsewhere = document.createElement('input')
    document.body.appendChild(elsewhere)

    await wrapper.find('button').trigger('click')
    elsewhere.focus()

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await nextTick()

    // Escape is caught at document level, so it fires from anywhere. Closing
    // is right; dragging the caret out of the field they moved on to is not.
    expect(panel()).toBeNull()
    expect(document.activeElement).toBe(elsewhere)
    elsewhere.remove()
  })

  it('closes when the next press lands outside it', async () => {
    const wrapper = mountPopover()
    const outside = document.createElement('button')
    document.body.appendChild(outside)

    await wrapper.find('button').trigger('click')
    expect(panel()).not.toBeNull()

    outside.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await nextTick()

    expect(panel()).toBeNull()
    outside.remove()
  })

  it('does NOT close when the press lands inside its own panel', async () => {
    const wrapper = mountPopover()

    await wrapper.find('button').trigger('click')
    const content = panel()
    expect(content).not.toBeNull()

    content?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await nextTick()

    // Selecting the text of a warning must not dismiss the warning.
    expect(panel()).not.toBeNull()
  })

  it('is operable by keyboard alone: focus, activate, dismiss, focus restored', async () => {
    const wrapper = mountPopover()
    const trigger = wrapper.find('button').element as HTMLButtonElement

    // Tab lands here.
    trigger.focus()
    expect(document.activeElement).toBe(trigger)

    // Enter/Space on a focused native <button> produce a click event — that is
    // the browser contract the component leans on, dispatched here directly
    // because jsdom does not synthesise it from a keydown.
    trigger.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await nextTick()
    expect(panel()).not.toBeNull()

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await nextTick()
    expect(panel()).toBeNull()
    expect(document.activeElement).toBe(trigger)
  })

  it('associates the content with the trigger for screen readers', async () => {
    const wrapper = mountPopover()
    const trigger = wrapper.find('button')

    // Closed: it announces as a collapsed control that names its own field.
    expect(trigger.attributes('aria-expanded')).toBe('false')
    const name = trigger.attributes('aria-label') ?? ''
    expect(name).toContain('ลบบทเรียน')
    // 32 identical "ดูคำอธิบาย" buttons on one screen would be unusable, so
    // the field name has to be part of the accessible name, not just the verb.
    expect(name.length).toBeGreaterThan('ดูคำอธิบาย'.length)

    await trigger.trigger('click')

    const id = panel()?.getAttribute('id')
    expect(id).toBeTruthy()
    expect(trigger.attributes('aria-expanded')).toBe('true')
    expect(trigger.attributes('aria-describedby')).toBe(id)
    expect(trigger.attributes('aria-controls')).toBe(id)
  })

  it('renders the panel outside the component tree so a card cannot clip it', async () => {
    // Reproduces the builder's two-pane grid: an overflow-hidden card is the
    // exact shape that cuts an absolutely-positioned popover in half.
    const wrapper = mount(
      {
        components: { InfoPopover },
        template: `
          <div class="card" style="overflow: hidden">
            <InfoPopover label="ลบบทเรียน" :text="text" />
          </div>
        `,
        data: () => ({ text: TEXT }),
      },
      { attachTo: document.body, global: { stubs: { Icon: true } } },
    )
    wrappers.push(wrapper)

    await wrapper.find('button').trigger('click')

    const content = panel()
    expect(content).not.toBeNull()
    // The panel must be a child of <body>, NOT of the clipping card.
    expect(content?.parentElement).toBe(document.body)
    expect(wrapper.find('.card').element.contains(content!)).toBe(false)
    expect(content?.style.position).toBe('fixed')
  })

  it('takes rich content from the default slot', async () => {
    const wrapper = mount(InfoPopover, {
      props: { label: 'เกณฑ์ผ่าน' },
      slots: { default: '<p class="rich">ค่าที่ใช้จริง 80%</p>' },
      attachTo: document.body,
      global: { stubs: { Icon: true } },
    })
    wrappers.push(wrapper)

    await wrapper.find('button').trigger('click')

    expect(panel()?.querySelector('.rich')?.textContent).toBe('ค่าที่ใช้จริง 80%')
  })

  it('removes every global listener it added when unmounted while open', async () => {
    // Asserting "the panel is gone" would prove nothing — Vue removes the
    // teleported node on unmount whether or not the listeners are cleaned up.
    // The listeners themselves have to be observed.
    const docAdd = vi.spyOn(document, 'addEventListener')
    const docRemove = vi.spyOn(document, 'removeEventListener')
    const winAdd = vi.spyOn(window, 'addEventListener')
    const winRemove = vi.spyOn(window, 'removeEventListener')

    const wrapper = mountPopover()
    await wrapper.find('button').trigger('click')

    const added = (spy: typeof docAdd, type: string) =>
      spy.mock.calls.filter((call) => call[0] === type).map((call) => call[1])

    const keydown = added(docAdd, 'keydown')
    const click = added(docAdd, 'click')
    const resize = added(winAdd, 'resize')
    const scroll = added(winAdd, 'scroll')
    expect(keydown).toHaveLength(1)
    expect(click).toHaveLength(1)
    expect(resize).toHaveLength(1)
    expect(scroll).toHaveLength(1)

    wrapper.unmount()
    wrappers.splice(wrappers.indexOf(wrapper), 1)

    // The builder tab mounts ~32 of these. A handler left on `document` per
    // popover per navigation is a leak that nothing on the screen reveals.
    expect(added(docRemove, 'keydown')).toContain(keydown[0])
    expect(added(docRemove, 'click')).toContain(click[0])
    expect(added(winRemove, 'resize')).toContain(resize[0])
    expect(added(winRemove, 'scroll')).toContain(scroll[0])

    docAdd.mockRestore()
    docRemove.mockRestore()
    winAdd.mockRestore()
    winRemove.mockRestore()
  })
})
