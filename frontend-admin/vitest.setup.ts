/**
 * TASK-225 — every component test gets a fresh Pinia.
 *
 * WHY THIS FILE EXISTS. ADR-038/TASK-209 gave the Admin app a global
 * "which company am I working in" store, and every view that can be
 * scoped now calls `useActiveCompanyStore()` in its own `setup()`. That
 * turned five spec files red at once with
 *
 *     "getActivePinia()" was called but there was no active Pinia
 *
 * — not because the specs test the wrong thing, but because mounting ANY
 * of these views is now a thing you cannot do without a Pinia. Adding
 * five copies of the same four lines would leave the sixth view to
 * rediscover this the same way.
 *
 * A FRESH Pinia PER TEST, not one shared instance: stores are stateful,
 * and a company selected in one test leaking into the next is the kind of
 * order-dependent failure that costs an afternoon to find.
 */
import { beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { config } from '@vue/test-utils'

beforeEach(() => {
  const pinia = createPinia()

  // setActivePinia covers stores reached OUTSIDE a component (a composable
  // called directly in a test); the global plugin covers stores reached
  // during setup() of anything mounted. Both are needed — neither implies
  // the other.
  setActivePinia(pinia)
  config.global.plugins = [pinia]
})
