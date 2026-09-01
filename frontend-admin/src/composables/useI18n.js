/**
 * useI18n — Lightweight bilingual composable (TH/EN)
 * sync กับ TopNavigation.vue ผ่าน window event 'lang-change'
 *
 * Usage ใน Vue component:
 *   import { useI18n } from '@/composables/useI18n'
 *   const { lang, t } = useI18n()
 *   <button>{{ t('add', 'เพิ่ม', 'Add') }}</button>
 */
import { ref, shallowRef, onMounted, onUnmounted } from 'vue'
// The read below happens at IMPORT time, so it must not be able to throw:
// anything thrown here takes down every component that imports this module,
// however far from i18n it is. See safeStorage.js for the full reasoning.
import { readStored, writeStored } from '../utils/safeStorage'

// Global reactive state — หนึ่ง instance ต่อทั้ง app
const lang = ref('TH')

const LANG_KEY = 'app_lang'

// Sprint TZI18N-1 — Priority:
//   1) window.tenantLocale.language (server-injected on page load)
//   2) localStorage (user's last manual override)
//   3) fallback 'TH'
if (typeof window !== 'undefined') {
    const tenantLang = window.tenantLocale?.language
    if (tenantLang === 'th') lang.value = 'TH'
    else if (tenantLang === 'en') lang.value = 'EN'

    const saved = readStored(LANG_KEY)
    if (saved === 'TH' || saved === 'EN') {
        // localStorage wins (user's explicit choice from switcher)
        lang.value = saved
    }
}

export function useI18n() {
    const handler = (e) => {
        if (e.detail === 'TH' || e.detail === 'EN') {
            lang.value = e.detail
            writeStored(LANG_KEY, e.detail)
        }
    }

    onMounted(() => {
        window.addEventListener('lang-change', handler)
    })
    onUnmounted(() => {
        window.removeEventListener('lang-change', handler)
    })

    /** t(key, thaiText, englishText) — เลือกตาม lang ปัจจุบัน (backward compat) */
    const t = (key, th, en) => (lang.value === 'EN' ? en : th)

    /**
     * td(dotKey, fallback) — Sprint TZI18N-2: dictionary lookup ผ่าน dot notation
     *   จาก /lang/th.json + /lang/en.json (โหลด lazy ครั้งแรกที่เรียกใช้)
     *
     *   Example: td('common.save')  → 'บันทึก' (TH) หรือ 'Save' (EN)
     *   Example: td('doc.receipt')  → 'ใบเสร็จรับเงิน' หรือ 'Receipt'
     *   Example: td('missing.key', 'ค่า default') → ใช้ fallback ถ้าไม่พบ key
     */
    /**
     * @param {string} dotKey
     * @param {string} [fallback]
     * @param {Record<string, string|number>|null} [params]  values for {slot}s
     * @returns {string}
     */
    const td = (dotKey, fallback = '', params = null) => {
        const dict = lang.value === 'EN' ? _dictEn.value : _dictTh.value
        let out = null
        if (dict) {
            let cur = dict
            for (const p of String(dotKey).split('.')) {
                if (cur && typeof cur === 'object' && p in cur) cur = cur[p]
                else { cur = null; break }
            }
            if (typeof cur === 'string') out = cur
        }
        if (out === null) out = fallback || dotKey

        /*
         * PLACEHOLDERS — 2026-09-22.
         *
         * Sentences that wrap a number or a name ("เรียนจบแล้ว 3 จาก 8 บทเรียน")
         * cannot be split into fragments and reassembled: Thai and English put
         * the pieces in different orders, and a template that concatenates
         * them produces correct Thai and broken English. So the whole sentence
         * is ONE dictionary entry with {named} slots, and each language decides
         * where its slots go.
         *
         * Values are substituted as plain text into a Vue interpolation, never
         * into v-html, so there is no markup for a value to escape into.
         */
        if (params) {
            for (const [k, v] of Object.entries(params)) {
                out = out.split('{' + k + '}').join(String(v))
            }
        }

        return out
    }

    /** dispatch event ให้ทั้ง app เปลี่ยน lang */
    const setLang = (newLang) => {
        if (newLang !== 'TH' && newLang !== 'EN') return
        lang.value = newLang
        writeStored(LANG_KEY, newLang)
        window.dispatchEvent(new CustomEvent('lang-change', { detail: newLang }))
    }

    return { lang, t, td, setLang }
}

/*
 * === Sprint TZI18N-2: dictionary state ===
 *
 * BUG FIX 2026-09-22 — these were plain module variables, and that made
 * td() silently useless.
 *
 * The dictionaries load asynchronously (fetch, below). A template that calls
 * td() renders BEFORE the fetch resolves, so it gets the fallback — and
 * because a plain `let` is not reactive, Vue is never told anything changed
 * and never re-renders. The English text arrived and nothing on screen ever
 * used it. Nobody had noticed because the JSON files did not exist yet, so
 * every call was returning its fallback for a second, more obvious reason.
 *
 * shallowRef, not ref: these are large read-only trees. Deep reactivity
 * would walk every key on assignment for no benefit — nothing ever mutates
 * a dictionary in place, it is replaced wholesale exactly once.
 */
const _dictTh = shallowRef(null)
const _dictEn = shallowRef(null)

async function _loadDict() {
    if (typeof window === 'undefined') return
    if (!_dictTh.value) {
        try {
            const res = await fetch('/lang/th.json', { cache: 'default' })
            if (res.ok) _dictTh.value = await res.json()
        } catch {}
    }
    if (!_dictEn.value) {
        try {
            const res = await fetch('/lang/en.json', { cache: 'default' })
            if (res.ok) _dictEn.value = await res.json()
        } catch {}
    }
}
// Kick off dictionary preload as soon as this module loads
if (typeof window !== 'undefined') _loadDict()

// === Common label dictionary (reuse ทั่วทั้ง app) ===
export const I18N = {
    // Actions
    add:         { th: 'เพิ่ม',        en: 'Add' },
    edit:        { th: 'แก้ไข',       en: 'Edit' },
    delete:      { th: 'ลบ',          en: 'Delete' },
    save:        { th: 'บันทึก',      en: 'Save' },
    cancel:      { th: 'ยกเลิก',      en: 'Cancel' },
    confirm:     { th: 'ยืนยัน',      en: 'Confirm' },
    search:      { th: 'ค้นหา',       en: 'Search' },
    filter:      { th: 'กรอง',        en: 'Filter' },
    refresh:     { th: 'รีเฟรช',     en: 'Refresh' },
    close:       { th: 'ปิด',         en: 'Close' },
    back:        { th: 'ย้อนกลับ',    en: 'Back' },
    next:        { th: 'ถัดไป',       en: 'Next' },
    previous:    { th: 'ก่อนหน้า',    en: 'Previous' },
    loading:     { th: 'กำลังโหลด...', en: 'Loading...' },
    saving:      { th: 'กำลังบันทึก...', en: 'Saving...' },
    deleting:    { th: 'กำลังลบ...',  en: 'Deleting...' },

    // Confirmation
    confirmDeleteTitle: { th: 'ยืนยันการลบ', en: 'Confirm Delete' },
    confirmDeleteBody:  { th: 'คุณต้องการลบรายการนี้ใช่หรือไม่? (Soft Delete — สามารถกู้คืนได้)', en: 'Delete this record? (Soft Delete — recoverable)' },

    // Status
    success:     { th: 'สำเร็จ',      en: 'Success' },
    error:       { th: 'ผิดพลาด',     en: 'Error' },
    notFound:    { th: 'ไม่พบข้อมูล', en: 'Not Found' },
    empty:       { th: 'ยังไม่มีข้อมูล', en: 'No data yet' },

    // Common fields
    name:        { th: 'ชื่อ',        en: 'Name' },
    status:      { th: 'สถานะ',       en: 'Status' },
    created:     { th: 'สร้างเมื่อ',  en: 'Created' },
    updated:     { th: 'อัปเดตล่าสุด', en: 'Updated' },
    actions:     { th: 'จัดการ',      en: 'Actions' },
    all:         { th: 'ทั้งหมด',     en: 'All' },
}
