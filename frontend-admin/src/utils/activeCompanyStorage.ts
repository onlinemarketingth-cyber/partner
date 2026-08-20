/**
 * TASK-226 — the one place that knows where the Super Admin's chosen
 * company is persisted, and the only channel `api/client.ts` may use to
 * read it.
 *
 * WHY A LEAF MODULE AND NOT `useActiveCompanyStore()`. The store imports
 * `@/api/client` (it fetches the company list), so the client importing
 * the store back would be a circular import — the kind that resolves to
 * `undefined` at module-evaluation time and fails only in a production
 * build. This file imports nothing of the app, so both sides can depend
 * on it safely.
 *
 * The store owns the VALUE and every rule around it (whose choice counts,
 * what null means, what happens on login). This file owns only the KEY
 * and how to read it back — deliberately no writer, so there is exactly
 * one thing in the app that can change the selection.
 *
 * Storage is reached through `safeStorage`, never `localStorage` directly:
 * this read runs at store-construction time, so anything thrown here takes
 * down the whole Admin app rather than losing one remembered preference.
 * See safeStorage.js for the cases that actually occur.
 */
import { readStored } from './safeStorage'

/** null = ทุกบริษัท (Super Admin's read-across view), or nothing chosen yet. */
export const ACTIVE_COMPANY_STORAGE_KEY = 'sva.admin.activeCompanyId'

export function readPersistedActiveCompanyId(): number | null {
  const raw = readStored(ACTIVE_COMPANY_STORAGE_KEY)
  if (raw === null || raw === '' || raw === 'all') return null
  const n = Number(raw)

  return Number.isFinite(n) ? n : null
}
