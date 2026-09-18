/**
 * 2026-09-18 — RowActions, the one-prominent-action-plus-⋯-menu control
 * (human: "ตอนนี้ดูยากไปหน่อยเรื่องปุ่ม เพราะสีมันไม่ชัดเจน … ลองใช้มาตรฐาน
 * Apple ดู", then choosing the menu pattern for the whole app).
 *
 * These tests are about the RULES the component exists to enforce, not about
 * its markup. Every one of them is something that was true of the four-button
 * row it replaces and had to stop being true:
 *
 *   • more than one loud button per row
 *   • a destructive action sitting flush against an everyday one
 *   • a control that is dead with nothing on screen saying why
 *   • a menu that cannot be closed or driven from the keyboard
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import RowActions from '../RowActions.vue'

const stubs = { Icon: { props: ['name', 'size'], template: '<i :data-icon="name" />' } }

/*
 * The panel is teleported to <body>, so these mounts leave nodes outside the
 * wrapper. They are cleaned up by UNMOUNTING rather than by wiping
 * document.body: blanking innerHTML tears out nodes Vue still holds
 * references to, and the next re-render dies on `insertBefore` of null —
 * which is a failure in the test harness that reads exactly like a bug in
 * the component.
 */
let mounted: ReturnType<typeof mount>[] = []

afterEach(() => {
  mounted.forEach((w) => w.unmount())
  mounted = []
})

function mountRow(props: Record<string, unknown> = {}) {
  const wrapper = mount(RowActions, {
    props: {
      items: [
        { label: 'แก้ไขข้อมูล', icon: 'pencil', test: 'edit-row' },
        { label: 'ไม่อนุมัติ', icon: 'x', test: 'reject-row' },
        { label: 'ลบ', icon: 'trash', test: 'remove-row', destructive: true },
      ],
      ...props,
    },
    global: { stubs },
    attachTo: document.body,
  })

  mounted.push(wrapper)

  return wrapper
}

const panel = () => document.querySelector<HTMLElement>('[data-test="row-menu-panel"]')

/*
 * jsdom gives every element a zero-sized box, so placement has to be fed its
 * inputs by hand: where the trigger sits, and how tall the panel came out.
 * Both are restored afterwards — a leaked offsetHeight getter would quietly
 * change the arithmetic of every other suite in the run.
 */
function stubGeometry({ triggerTop, viewportHeight, panelHeight }: {
  triggerTop: number
  viewportHeight: number
  panelHeight: number
}) {
  const realOffsetHeight = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'offsetHeight')
  const realRect = HTMLElement.prototype.getBoundingClientRect

  Object.defineProperty(window, 'innerHeight', { configurable: true, writable: true, value: viewportHeight })
  Object.defineProperty(HTMLElement.prototype, 'offsetHeight', {
    configurable: true,
    get(this: HTMLElement) {
      return this.dataset.test === 'row-menu-panel' ? panelHeight : 0
    },
  })
  HTMLElement.prototype.getBoundingClientRect = function () {
    if (this.dataset.test === 'row-menu') {
      return { top: triggerTop, bottom: triggerTop + 36, left: 500, right: 536, width: 36, height: 36, x: 500, y: triggerTop, toJSON: () => ({}) } as DOMRect
    }

    return realRect.call(this)
  }

  return () => {
    HTMLElement.prototype.getBoundingClientRect = realRect
    if (realOffsetHeight) Object.defineProperty(HTMLElement.prototype, 'offsetHeight', realOffsetHeight)
  }
}
const item = (test: string) => document.querySelector<HTMLButtonElement>(`[data-test="${test}"]`)

describe('RowActions — one loud thing at a time', () => {
  it('renders at most one filled button, and the rest are behind the menu', async () => {
    /*
     * THE WHOLE POINT. The row this replaces had four buttons in identical
     * 2px navy borders, so "ลบ" — which is hard to undo — pulled the eye
     * exactly as hard as "แก้ไข", which an admin does every day.
     */
    const wrapper = mountRow({ primary: { label: 'อนุมัติ', test: 'approve-row', tone: 'positive' } })

    const buttons = wrapper.findAll('button')
    expect(buttons).toHaveLength(2) // the primary, and ⋯
    expect(wrapper.find('[data-test="approve-row"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="row-menu"]').exists()).toBe(true)
    // …and nothing else is on the row until the menu is opened.
    expect(item('edit-row')).toBeNull()
  })

  it('renders only the menu when a row has no prominent action', async () => {
    // A row whose actions are all secondary must not promote one of them to
    // filled just to have something there.
    const wrapper = mountRow()

    expect(wrapper.findAll('button')).toHaveLength(1)
  })

  it('hides the menu button entirely when there is nothing in it', async () => {
    // An empty menu is a control that does nothing, which reads as broken.
    const wrapper = mountRow({ items: [], primary: { label: 'อนุมัติ', test: 'approve-row' } })

    expect(wrapper.find('[data-test="row-menu"]').exists()).toBe(false)
  })
})

describe('RowActions — the menu', () => {
  it('opens, lists the items, and runs the one that was chosen', async () => {
    const onSelect = vi.fn()
    const wrapper = mountRow({
      items: [{ label: 'แก้ไขข้อมูล', test: 'edit-row', onSelect }],
    })

    await wrapper.find('[data-test="row-menu"]').trigger('click')
    expect(panel()).not.toBeNull()

    item('edit-row')!.click()
    expect(onSelect).toHaveBeenCalledTimes(1)
  })

  it('closes after a choice, so the next click lands on the row', async () => {
    const wrapper = mountRow()

    await wrapper.find('[data-test="row-menu"]').trigger('click')
    item('edit-row')!.click()
    await wrapper.vm.$nextTick()

    expect(panel()).toBeNull()
  })

  it('puts destructive items last, behind a separator', async () => {
    /*
     * Order and the separator do the work that red alone cannot: a
     * colour-blind admin gets position and a divider, which are not colours.
     * Passing ลบ first must NOT put it first.
     */
    const wrapper = mountRow({
      items: [
        { label: 'ลบ', test: 'remove-row', destructive: true },
        { label: 'แก้ไขข้อมูล', test: 'edit-row' },
      ],
    })

    await wrapper.find('[data-test="row-menu"]').trigger('click')

    const order = Array.from(panel()!.querySelectorAll('[data-menuitem]')).map((b) => b.getAttribute('data-test'))
    expect(order).toEqual(['edit-row', 'remove-row'])
    expect(panel()!.querySelector('[data-test="row-menu-separator"]')).not.toBeNull()
  })

  it('says why a disabled item is disabled, on screen', async () => {
    /*
     * Not in a `title`: a tooltip never opens on a touch screen, which is
     * CLAUDE.md §7's own objection to `title=`. An admin on a tablet would
     * see a grey row and no explanation.
     */
    const wrapper = mountRow({
      items: [{
        label: 'ลบ',
        test: 'remove-row',
        destructive: true,
        disabled: true,
        disabledReason: 'มีลูกค้าที่แนะนำ 3 ราย',
      }],
    })

    await wrapper.find('[data-test="row-menu"]').trigger('click')

    expect(item('remove-row')!.disabled).toBe(true)
    expect(document.querySelector('[data-test="remove-row-reason"]')!.textContent).toContain('3 ราย')
  })

  it('does not run a disabled item that is clicked anyway', async () => {
    const onSelect = vi.fn()
    const wrapper = mountRow({
      items: [{ label: 'ลบ', test: 'remove-row', destructive: true, disabled: true, onSelect }],
    })

    await wrapper.find('[data-test="row-menu"]').trigger('click')
    item('remove-row')!.click()

    expect(onSelect).not.toHaveBeenCalled()
  })

  it('closes on Escape', async () => {
    const wrapper = mountRow()

    await wrapper.find('[data-test="row-menu"]').trigger('click')
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await wrapper.vm.$nextTick()

    expect(panel()).toBeNull()
  })

  it('closes when the page scrolls, rather than chasing the row', async () => {
    // These rows live inside horizontally scrolling tables. A teleported
    // panel that stayed put while its row moved would be pointing at
    // somebody else's account.
    const wrapper = mountRow()

    await wrapper.find('[data-test="row-menu"]').trigger('click')
    window.dispatchEvent(new Event('scroll'))
    await wrapper.vm.$nextTick()

    expect(panel()).toBeNull()
  })

  it('is teleported out of the row so a scrolling table cannot clip it', async () => {
    /*
     * The failure this prevents is invisible and total: an absolutely
     * positioned dropdown inside `overflow-x-auto` is cut off, so the button
     * appears to do nothing at all.
     */
    const wrapper = mountRow()

    await wrapper.find('[data-test="row-menu"]').trigger('click')

    expect(panel()!.closest('[data-test="row-menu"]')).toBeNull()
    expect(panel()!.parentElement).toBe(document.body)
  })
})

/**
 * 2026-09-18 (human, on a screenshot of the last row on screen: "เนื่องจาก
 * เป็นบรรทัดสุดท้ายของหน้าจอ เลยแสดงผลไม่ได้เห็นไม่ครบ").
 *
 * The first cut clamped left and right and forgot the bottom entirely, so on
 * the last visible row the menu ran off the screen — and since destructive
 * items sit LAST, the part you could not see was the delete.
 */
describe('RowActions — staying on screen', () => {
  it('opens downward when there is room', async () => {
    const restore = stubGeometry({ triggerTop: 100, viewportHeight: 800, panelHeight: 180 })
    const wrapper = mountRow()

    await wrapper.find('[data-test="row-menu"]').trigger('click')
    await wrapper.vm.$nextTick()

    // trigger bottom 136 + the 6px gap
    expect(panel()!.style.top).toBe('142px')
    restore()
  })

  it('flips above the trigger on the last row, instead of running off the bottom', async () => {
    // A 180px menu under a trigger with 60px of window left below it.
    const restore = stubGeometry({ triggerTop: 704, viewportHeight: 800, panelHeight: 180 })
    const wrapper = mountRow()

    await wrapper.find('[data-test="row-menu"]').trigger('click')
    await wrapper.vm.$nextTick()

    // 704 − 6 gap − 180 tall = 518, which keeps the whole menu on screen.
    expect(panel()!.style.top).toBe('518px')
    restore()
  })

  it('never lets the panel hang past the bottom edge', async () => {
    const restore = stubGeometry({ triggerTop: 704, viewportHeight: 800, panelHeight: 180 })
    const wrapper = mountRow()

    await wrapper.find('[data-test="row-menu"]').trigger('click')
    await wrapper.vm.$nextTick()

    expect(Number.parseInt(panel()!.style.top, 10) + 180).toBeLessThanOrEqual(800)
    restore()
  })

  it('scrolls inside itself when neither side can hold it whole', async () => {
    // A long menu in a short window: something has to give, and an item
    // nobody can reach is worse than a scrollbar.
    const restore = stubGeometry({ triggerTop: 150, viewportHeight: 320, panelHeight: 600 })
    const wrapper = mountRow()

    await wrapper.find('[data-test="row-menu"]').trigger('click')
    await wrapper.vm.$nextTick()

    const maxHeight = Number.parseInt(panel()!.style.maxHeight, 10)
    expect(maxHeight).toBeGreaterThan(0)
    expect(maxHeight).toBeLessThan(600)
    restore()
  })

  it('is never left sitting at the window origin', async () => {
    /*
     * Placement needs the panel's height, which needs the panel to exist —
     * so it renders one frame BEFORE it is positioned, and is held invisible
     * for that frame (the `placed` flag). What is observable, and what
     * actually matters, is that by the time anyone can see it, it carries
     * real coordinates rather than the 0,0 it was born with.
     */
    const restore = stubGeometry({ triggerTop: 100, viewportHeight: 800, panelHeight: 180 })
    const wrapper = mountRow()

    await wrapper.find('[data-test="row-menu"]').trigger('click')
    await wrapper.vm.$nextTick()

    expect(panel()!.className).not.toContain('opacity-0')
    expect(panel()!.style.top).not.toBe('0px')
    expect(panel()!.style.left).not.toBe('0px')
    restore()
  })
})
