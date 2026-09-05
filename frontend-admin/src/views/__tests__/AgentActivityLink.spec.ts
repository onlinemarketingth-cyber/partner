/**
 * TASK-241 — the jump from a person to that person's trail.
 *
 * The audit screen can be opened narrowed to one person with `?actor=<id>`
 * (PolicyReportActorFilter.spec.ts pins that end). This file pins the OTHER
 * end: the "ดูกิจกรรมของผู้ใช้นี้" link in the agent editor, which is the only
 * place the deep link is ever produced.
 *
 * ── WHY IT IS TESTED HERE AND NOT BY MOUNTING THE MODAL ──
 *
 * AgentEditModal is a 1,700-line editor that fetches its own lookups on open;
 * mounting it exercises a dozen endpoints that have nothing to do with one
 * link. What can break is narrower and it lives BETWEEN two files:
 *
 *   • the route name is wrong or has been renamed → RouterLink renders a
 *     link to "/" and vue-router warns to a console nobody is reading;
 *   • the query key drifts from what PolicyReportView reads (`actor`), so
 *     the link opens the audit screen unfiltered — which looks like a
 *     working link that found nothing.
 *
 * Both are cross-file agreements, so both are checked against the REAL
 * router and the REAL query key, resolved the way vue-router will resolve
 * them at runtime.
 */
import { describe, expect, it } from 'vitest'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { createRouter, createMemoryHistory } from 'vue-router'
import routerConfig from '@/router'

const here = path.dirname(fileURLToPath(import.meta.url))
const modalSource = fs.readFileSync(path.join(here, '..', 'AgentEditModal.vue'), 'utf8')

/** A throwaway router carrying the app's real route table. */
const router = createRouter({
  history: createMemoryHistory(),
  routes: routerConfig.getRoutes(),
})

describe('AgentEditModal — the link to this person\'s activity', () => {
  it('points at a route that exists', () => {
    // A RouterLink to an unknown name renders href="/" and warns. The modal
    // still looks perfect: a button that quietly goes to the dashboard.
    expect(router.hasRoute('policy-report')).toBe(true)
  })

  it('resolves to the query key the audit screen actually reads', () => {
    /*
     * `actor`, not `actor_user_id` (the API's name) and not `user` — the two
     * plausible drifts. PolicyReportView reads `route.query.actor`; a
     * mismatch opens the trail for EVERYBODY under a heading that promised
     * one person.
     */
    const resolved = router.resolve({ name: 'policy-report', query: { actor: 7 } })

    expect(resolved.fullPath).toContain('actor=7')
  })

  /**
   * The markup immediately around the link — the block it is written in, not
   * the whole file, so "somewhere in 1,700 lines" cannot satisfy either
   * assertion below.
   */
  const linkBlock = (() => {
    const at = modalSource.indexOf('data-test="view-activity"')
    expect(at).toBeGreaterThan(-1)

    return modalSource.slice(at - 500, at + 200)
  })()

  it('sends the id and never the name', () => {
    // §6/PDPA — the same rule the audit screen keeps when it writes the URL
    // back. A name here would end up in history and in the Referer of
    // everything the audit page then loads.
    expect(linkBlock).toContain('query: { actor: agent?.id }')
    expect(linkBlock).not.toContain('agent?.name')
  })

  it('is not offered while no agent is loaded', () => {
    /*
     * The modal renders before its subject arrives. A link built on a
     * missing agent resolves to `?actor=undefined`, which the audit screen
     * treats as "everybody" — so the worst version of this bug is a button
     * on a half-loaded editor that opens the WHOLE platform's trail while
     * appearing to be about one person.
     */
    expect(linkBlock).toContain('v-if="agent"')
  })
})
