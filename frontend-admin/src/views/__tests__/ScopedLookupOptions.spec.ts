/**
 * 2026-09-09 (human: "เวลาเพิ่มสินค้า หมวดหมู่ แบรนด์ขึ้นซ้อนกัน ต้องมีตัวเดียว",
 * with a screenshot of "De La Lita" three times and "Anti Aging" three times).
 *
 * They were not duplicates. They were one row per company, all named the same,
 * and choosing between them was choosing which company's brand to attach — a
 * question the form never asked and the labels could not answer.
 *
 * A Super Admin is exempt from the tenant scope, so an unscoped GET /brands
 * answers with EVERY company's rows. Two screens got this right and two did
 * not, and the difference only becomes visible once a second company owns a
 * brand by the same name — which is exactly what promoting products to the
 * platform arranges.
 *
 * The two fixes are different because the two screens are:
 *
 *   THE PRODUCT FORM narrows on the SERVER (scopedPath → company_id), the way
 *   the catalogue list beside it already did. It only ever edits one company's
 *   product, so it has no use for the others.
 *
 *   THE COMMISSION SCREEN narrows in the BROWSER (byCompany), because it must
 *   keep platform rows whose company_id is null and it needs each row's own
 *   company_id to decide. Its product picker already did this; its category
 *   picker did not — and a rule attached to another company's category never
 *   matches, so the sale quietly pays the company default rate into a ledger
 *   that cannot be corrected.
 */
import { describe, expect, it } from 'vitest'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const read = (name: string) => fs.readFileSync(path.join(here, '..', name), 'utf8')

describe('ProductEditView — the brand and category pickers are scoped', () => {
  const source = read('ProductEditView.vue')

  it('asks the server for this company\'s brands, not everybody\'s', () => {
    expect(source).toContain("activeCompany.scopedPath('/brands')")
  })

  it('does the same for categories', () => {
    expect(source).toContain("activeCompany.scopedPath('/product-categories')")
  })

  it('marks a platform row so the remaining pair is not a coin flip', () => {
    /*
     * Scoping removes the OTHER companies' copies. What legitimately stays is
     * the company's own row and the platform's, both named "De La Lita" — and
     * a form that prints the bare name twice is asking somebody to guess.
     */
    expect(source).toContain('function taxonomyLabel')
    expect(source).toContain('ของกลาง')
    expect(source).toContain('{{ taxonomyLabel(b) }}')
    expect(source).toContain('{{ taxonomyLabel(c) }}')
  })

  it('no longer fetches either one unscoped', () => {
    /*
     * The regression this file exists for. Both calls sat one line apart from
     * the scoped ones in ProductCatalogView, so the bare form is the easy
     * thing to reintroduce — and it looks completely correct until a second
     * company owns a brand with the same name.
     */
    expect(source).not.toContain("api.get<{ data: Brand[] }>('/brands')")
    expect(source).not.toContain("api.get<{ data: ProductCategory[] }>('/product-categories')")
  })
})

describe('CommissionPlansView — the category picker narrows like the product one', () => {
  const source = read('CommissionPlansView.vue')

  it('runs category options through byCompany()', () => {
    // Not scopedPath: this screen must keep platform rows (company_id null),
    // which a server-side narrow to one company would drop.
    expect(source).toContain('v-for="c in byCompany(productCategories)"')
  })

  it('never renders the unnarrowed list', () => {
    expect(source).not.toContain('v-for="c in productCategories"')
  })

  it('carries company_id on the option so byCompany can decide', () => {
    // byCompany reads company_id; an option type without it would compile to
    // "keep everything" and silently do nothing.
    expect(source).toMatch(/interface ProductCategoryOption \{[^}]*company_id/)
  })
})
