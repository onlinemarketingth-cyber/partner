/**
 * ProgressRing takes BOTH of its colours from the tenant's theme.
 *
 * ── THE BUG THIS PINS (2026-08-21) ──
 *
 * A human on a gold-themed company looked at their Home screen and asked
 * what the blue ring was. It was blue because two colours were hardcoded:
 * the arc (`#4f46e5` by default, and HomeView passed the platform's own
 * `#2F4183` over the top) and the track (`#e2e8f0`, the LIGHT-mode value of
 * `--line-card`). Every other control on that screen was themed; these two
 * circles were not.
 *
 * ── WHY A TEST AND NOT JUST THE FIX ──
 *
 * A literal hex in a component is invisible on the default tenant — it is
 * RIGHT there, by coincidence — so this class of bug ships, survives review,
 * and is only ever found by someone who happens to run a differently-themed
 * company and happens to look. There is nothing to throw and nothing to log.
 * jsdom computes no styles, so these assert the ATTRIBUTES, which is the
 * layer the mistake actually lives at.
 *
 * The rule, stated once: any colour in this component must be a
 * `var(--…)` reference. tailwind.config.js holds the same tokens as
 * `rgb(var(--x) / <alpha-value>)`; theme.ts rewrites the variables per
 * company (applyRamp for the brand ramp, a card-bg/card-ink mix for
 * `--line-card`).
 */
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ProgressRing from '../ProgressRing.vue'

function mountRing(props: Record<string, unknown> = {}) {
  return mount(ProgressRing, {
    props: { fraction: 0.5, centerText: 'Lv 3', ...props },
  })
}

/** [track, arc] — the track is drawn first so the arc sits on top of it. */
function strokes(wrapper: ReturnType<typeof mountRing>) {
  return wrapper.findAll('circle').map((c) => c.attributes('stroke') ?? '')
}

describe('ProgressRing — colours come from the theme, never a literal', () => {
  it('draws the arc in the tenant\'s brand colour by default', () => {
    expect(strokes(mountRing())[1]).toBe('rgb(var(--brand-600))')
  })

  it('draws the track in the tenant\'s card hairline, not a fixed slate', () => {
    // #e2e8f0 is --line-card's LIGHT value. Hardcoding it left a bright ring
    // on a dark company, where theme.ts had derived a dark hairline.
    expect(strokes(mountRing())[0]).toBe('rgb(var(--line-card))')
  })

  it('has no hardcoded colour anywhere in its markup', () => {
    // The catch-all. A future "just use slate-200 for the empty part" is the
    // shape this bug came in, and it would pass both tests above.
    expect(mountRing().html()).not.toMatch(/#[0-9a-fA-F]{3,8}\b/)
  })

  it('still lets a caller override the arc for a non-brand meaning', () => {
    // A danger or success ring is a real case; what must not come back is
    // passing BRAND explicitly, which is what the default now covers.
    expect(strokes(mountRing({ color: 'rgb(var(--ink-danger))' }))[1])
      .toBe('rgb(var(--ink-danger))')
  })

  it('leaves the track alone when the arc is overridden', () => {
    expect(strokes(mountRing({ color: 'rgb(var(--ink-danger))' }))[0])
      .toBe('rgb(var(--line-card))')
  })
})
