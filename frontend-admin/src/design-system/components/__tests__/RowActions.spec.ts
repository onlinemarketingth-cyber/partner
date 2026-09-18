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

const panel = () => document.querySelector('[data-test="row-menu-panel"]')
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
