/**
 * The words on the agent's screen are for the agent.
 *
 * Owner, 2026-09-15, pointing at the ค่าแนะนำ header: "รายการ ledger เป็นแบบ
 * อ่านอย่างเดียว — ไม่มีการแก้ไขย้อนหลัง (BR-4) … เอาออก หรือต้องแจ้งผู้ใช้
 * เป็นอะไร เพราะตอนนี้ มันคำเตือนระบบ".
 *
 * They were right, and it was not one line. Fourteen strings in the agent's
 * dictionary carried an internal business-rule id — BR-1, BR-4, BR-5, BR-7,
 * ADR-011 — or an English schema word (`ledger`, `Pipeline`, `Referral`,
 * `Complete Payment`, `trigger`) that appears nowhere in the Thai UI. Every
 * one of them was written while the rule was fresh in somebody's head, and
 * every one of them reads, to the person selling insurance on a phone, like a
 * system warning about something being broken or restricted.
 *
 * ── WHY A TEST AND NOT JUST A FIX ──
 *
 * Fixing fourteen strings takes ten minutes and holds until the next feature
 * is written with the rule id still on screen. This file is the part that
 * lasts: a rule id in agent-facing copy fails the build, in both languages, on
 * the day it is written rather than when the owner next reads their own app.
 *
 * It does NOT check for jargon in general — no test can — and it deliberately
 * allows the words in a `_meta` note, which is documentation for whoever edits
 * the file and is never rendered.
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

type Dict = { [key: string]: string | Dict }

function flatten(dict: Dict, prefix = ''): Array<[string, string]> {
  return Object.entries(dict).flatMap(([key, value]) =>
    typeof value === 'string'
      ? [[prefix + key, value] as [string, string]]
      : flatten(value, `${prefix}${key}.`),
  )
}

function load(lang: 'th' | 'en'): Array<[string, string]> {
  const raw = readFileSync(resolve(__dirname, `../../../public/lang/${lang}.json`), 'utf-8')
  const dict = JSON.parse(raw) as Dict

  // `_meta` is a note to whoever edits the file. It is never rendered, so it
  // is the one place these words are allowed to appear.
  return flatten(dict).filter(([key]) => !key.startsWith('_meta'))
}

/**
 * The identifiers this codebase uses in its own documentation.
 *
 * They are genuinely useful — in CLAUDE.md, in a docblock, in a commit
 * message. They mean nothing to an agent, and printed in the middle of a
 * sentence they read as an error code.
 */
const INTERNAL_RULE_ID = /\b(?:BR|TASK|ADR|F)-\d+\b/

describe('no internal rule ids reach the agent', () => {
  it.each(['th', 'en'] as const)('%s.json', (lang) => {
    const offenders = load(lang)
      .filter(([, value]) => INTERNAL_RULE_ID.test(value))
      .map(([key, value]) => `${key} = ${value}`)

    expect(offenders).toEqual([])
  })
})

/**
 * Schema words that were never translated.
 *
 * Thai only: these are ordinary English words in an English sentence, and
 * en.json is allowed to say "pipeline" the way the English UI labels it. What
 * the Thai dictionary may not do is print the column name.
 *
 * `ledger` is the one that started this. The others are the stage and model
 * names from the backend, which a Thai reader has never seen anywhere else in
 * the app — the same stage is labelled "รับชำระเงินแล้ว" on the screen that
 * shows it.
 */
const UNTRANSLATED_SCHEMA_WORDS = [
  'ledger',
  'payment_status',
  'satang',
  'Complete Payment',
  'trigger',
]

describe('the Thai copy does not print backend words at a Thai reader', () => {
  it('names none of them', () => {
    const offenders = load('th')
      .filter(([, value]) =>
        UNTRANSLATED_SCHEMA_WORDS.some((word) => value.toLowerCase().includes(word.toLowerCase())),
      )
      .map(([key, value]) => `${key} = ${value}`)

    expect(offenders).toEqual([])
  })
})

describe('what the agent is told instead', () => {
  /*
   * The header this started from. "อ่านอย่างเดียว / ไม่มีการแก้ไขย้อนหลัง" is a
   * promise the SYSTEM makes to itself; the agent's question standing in front
   * of this screen is "when does my money show up here".
   */
  it('the ค่าแนะนำ header answers when commission appears, not how the table is stored', () => {
    const description = Object.fromEntries(load('th'))['commission.description']

    expect(description).toContain('ชำระเงิน')
    expect(description).not.toContain('อ่านอย่างเดียว')
  })

  it('the immutability warning survives where it is actually a warning', () => {
    /*
     * NOT everything went. Before an agent confirms a payment, "this cannot be
     * changed afterwards" is a real consequence of a real press, and dropping
     * it to tidy up the rule ids would have removed the one place the rule is
     * the reader's business.
     */
    const warning = Object.fromEntries(load('th'))['pipeline.commission_warning']

    expect(warning).toContain('แก้ไขย้อนหลังไม่ได้')
    expect(warning).not.toContain('BR-4')
  })
})
