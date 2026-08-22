/**
 * HeroHeader's COLLAPSED row: a title and its numbers, nothing else.
 *
 * ── THE SCREENSHOT THAT CAUSED THIS (2026-08-21) ──
 *
 * On /commission at a medium width the row read:
 *
 *     [คอมมิชชั่นเดือนนี้ 0 บาท] [รอจ่าย 0] [จ่ายแล้ว 0]  (icon)  ค่..  ส..  ⌄
 *
 * Three faults reading as one mess, and none of them throws anything:
 *   1. the KPIs rendered BEFORE the icon and title — the icon/title blocks
 *      carried `order-1`, the KPI block carried no order at all (order-0);
 *   2. the title was `truncate flex-1` and lost to the KPIs, so a two-word
 *      page name became "ค่..";
 *   3. the subtitle was `truncate hidden md:block`, i.e. it only appeared at
 *      the widths where the KPIs were competing for the same row, and so
 *      only ever rendered as "ส..".
 *
 * A layout that degrades to ellipses does not fail a build, does not log,
 * and looks fine on the developer's wide window. So the rule is pinned here
 * rather than left to review.
 */
import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import HeroHeader from '../HeroHeader.vue'

// HeroHeader publishes its title to the page-header store (it is what feeds
// the sticky mobile bar), so a bare mount needs an active Pinia.
beforeEach(() => {
  setActivePinia(createPinia())
  window.sessionStorage.clear()
  window.localStorage.clear()
})

const KPIS = [
  { label: 'ค่าแนะนำเดือนนี้', value: '0 บาท' },
  { label: 'รอจ่าย', value: '0 บาท' },
  { label: 'จ่ายแล้ว', value: '0 บาท' },
]

function mountHeader(props: Record<string, unknown> = {}) {
  return mount(HeroHeader, {
    props: {
      icon: 'money',
      title: 'ค่าแนะนำ',
      subtitle: 'สรุปค่าแนะนำของคุณ',
      kpis: KPIS,
      ...props,
    },
    global: { stubs: { Icon: true } },
  })
}

/** The collapsed row is the one that is NOT the expanded `.p-5` hero. */
function collapsedRow(wrapper: ReturnType<typeof mountHeader>) {
  return wrapper.find('.flex-wrap.items-center')
}

describe('HeroHeader — collapsed', () => {
  it('shows the page title in full, never truncated', () => {
    // "where am I" is the one thing on this row that must always survive.
    const h1 = mountHeader({ defaultCollapsed: true }).find('h1')

    expect(h1.text()).toBe('ค่าแนะนำ')
    expect(h1.classes()).not.toContain('truncate')
    expect(h1.classes()).toContain('whitespace-nowrap')
  })

  it('drops the subtitle rather than rendering it as an ellipsis', () => {
    expect(mountHeader({ defaultCollapsed: true }).text()).not.toContain('สรุปค่าแนะนำของคุณ')
  })

  it('drops the decorative icon halo', () => {
    // Collapsed mode exists to buy back vertical space, and the glyph is
    // already lit in the bottom nav.
    expect(collapsedRow(mountHeader({ defaultCollapsed: true })).find('.bg-surface-chip').exists())
      .toBe(false)
  })

  it('keeps the KPIs, and keeps them AFTER the title', () => {
    // The ordering fault: both blocks must sit at order-1 so neither can
    // jump the other again. Asserted on the class because jsdom computes no
    // layout — the class IS the mechanism here.
    const row = collapsedRow(mountHeader({ defaultCollapsed: true }))
    const kpiBlock = row.findAll('div').find((d) => d.text().includes('รอจ่าย'))

    expect(kpiBlock).toBeDefined()
    expect(kpiBlock!.classes()).toContain('order-1')
    expect(row.find('h1').element.parentElement?.className).toContain('order-1')
  })
})

describe('HeroHeader — expanded', () => {
  it('opens expanded when the caller asks for it', () => {
    // /commission passes :default-collapsed="false" so the summary is
    // readable on arrival instead of needing a tap first.
    const wrapper = mountHeader({ defaultCollapsed: false })

    expect(wrapper.text()).toContain('สรุปค่าแนะนำของคุณ')
  })

  it('still carries the icon, which collapsed mode gave up', () => {
    expect(mountHeader({ defaultCollapsed: false }).find('.bg-surface-chip').exists()).toBe(true)
  })

  it('defaults to collapsed for callers that say nothing', () => {
    // Unchanged behaviour for every other screen — only /commission opted
    // out, and this is what stops that opt-in becoming a global default by
    // accident.
    expect(mountHeader().text()).not.toContain('สรุปค่าแนะนำของคุณ')
  })
})
