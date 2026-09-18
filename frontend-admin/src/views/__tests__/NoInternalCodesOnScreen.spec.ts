/**
 * No internal rule or ticket id ever reaches a screen.
 *
 * Owner, 2026-09-18, pointing at a row that read "ยังไม่ผ่าน Basic (BR-1)":
 * "คำว่า BR-1 ไม่เข้าใจ คือศัพท์ technic หรือไม่ ถ้าใช่เปลี่ยน".
 *
 * It was not one line. Twenty-five strings across both apps carried a
 * BR-, ADR-, TASK-, ERD- or CI- id — and a few carried worse (a database
 * table name, the word `hardcode`, "seed placeholder"). Every one was
 * written while the rule was fresh in somebody's head, and every one reads,
 * to the person using the product, as a code they are supposed to know and
 * do not.
 *
 * ── WHY A TEST AND NOT JUST THE FIX ──
 *
 * The agent app already learned this on 2026-09-15 and got a guard
 * (frontend/src/views/__tests__/AgentCopyIsForAgents.spec.ts) — but that one
 * only reads the i18n dictionary, and every leak found this time was in a
 * `.vue` template, where it could not see. Fixing the strings takes an hour
 * and holds until the next screen is written with the rule id still on
 * screen. This is the part that lasts.
 *
 * ── WHAT IT DELIBERATELY DOES NOT DO ──
 *
 * It does not judge jargon in general; no test can. It checks for ID
 * PATTERNS only, and only on lines that carry Thai text and sit outside a
 * comment — because in a COMMENT a rule id is exactly right, and CLAUDE.md
 * §7 says comments stay English and cite their source. Citing BR-4 next to
 * the code that implements BR-4 is the good kind of reference; printing it
 * at somebody reading their own commission is not.
 */
import { describe, expect, it } from 'vitest'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'

const SRC = resolve(__dirname, '../../..')

/** BR-1, ADR-025, TASK-058, ERD-001, CI-002 — ids, not words. */
const INTERNAL_ID = /\b(BR|ADR|TASK|ERD|CI)-\d+/
const THAI = /[฀-๿]/

function sourceFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((entry) => {
    const path = join(dir, entry)
    if (statSync(path).isDirectory()) {
      return entry === '__tests__' || entry === 'node_modules' ? [] : sourceFiles(path)
    }

    return /\.(vue|ts)$/.test(entry) ? [path] : []
  })
}

/**
 * Lines that a user could actually read: Thai text, outside every kind of
 * comment this codebase uses.
 *
 * Block state is tracked across lines because the long explanatory comments
 * in these files run for twenty lines at a time, and their continuation
 * lines carry no marker of their own — a naive per-line check reports every
 * one of them and the guard gets switched off within a week.
 */
function userVisibleLines(source: string): Array<{ line: number; text: string }> {
  const out: Array<{ line: number; text: string }> = []
  let inBlock = false
  let inHtmlComment = false

  source.split('\n').forEach((raw, index) => {
    let line = raw

    if (inHtmlComment) {
      const end = line.indexOf('-->')
      if (end === -1) return
      line = line.slice(end + 3)
      inHtmlComment = false
    }

    if (inBlock) {
      const end = line.indexOf('*/')
      if (end === -1) return
      line = line.slice(end + 2)
      inBlock = false
    }

    // Strip complete comments, then notice any that open and do not close.
    line = line.replace(/<!--[\s\S]*?-->/g, '').replace(/\/\*[\s\S]*?\*\//g, '')

    if (line.includes('<!--')) {
      line = line.slice(0, line.indexOf('<!--'))
      inHtmlComment = true
    }
    if (line.includes('/*')) {
      line = line.slice(0, line.indexOf('/*'))
      inBlock = true
    }
    if (/(^|\s)\/\//.test(line)) {
      line = line.replace(/(^|\s)\/\/.*$/, '')
    }

    if (THAI.test(line) && INTERNAL_ID.test(line)) {
      out.push({ line: index + 1, text: line.trim() })
    }
  })

  return out
}

describe('the words on screen are for the people using the product', () => {
  it('no admin screen prints an internal rule, ADR or ticket id', () => {
    const offenders = sourceFiles(SRC).flatMap((file) =>
      userVisibleLines(readFileSync(file, 'utf-8')).map(
        ({ line, text }) => `${relative(SRC, file)}:${line}  ${text}`,
      ),
    )

    expect(
      offenders,
      `These lines show an internal id to a user. Say what the rule MEANS instead:\n${offenders.join('\n')}`,
    ).toEqual([])
  })

  it('catches an id that a future screen puts in front of a user', () => {
    // The guard above passes trivially the day everything is clean, so this
    // proves it can still fail. Both halves matter: the id is caught in a
    // real string, and NOT caught where it belongs.
    expect(userVisibleLines('<p>ยังไม่ผ่าน Basic (BR-1)</p>')).toHaveLength(1)
    expect(userVisibleLines('// BR-1 — สมาชิกต้องผ่าน Basic ก่อนจึงจะขายได้')).toEqual([])
    expect(userVisibleLines('/* BR-1 — สมาชิกต้องผ่าน Basic\n   ก่อนจึงจะขายได้ (BR-1) */')).toEqual([])
    expect(userVisibleLines('<!-- BR-1 — สมาชิกต้องผ่าน Basic ก่อน -->')).toEqual([])
  })
})
