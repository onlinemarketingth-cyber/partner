/**
 * useFontSize — ปรับขนาดตัวอักษรทั้งระบบ 3 ระดับ
 * Default = medium · persist ใน localStorage
 *
 * 🔧 v2 fix: Apply ผ่าน inline style.fontSize ตรง ๆ (กัน CSS specificity ติด)
 *           Scale 13/16/19px เห็นความต่างชัด
 *           Force re-apply on mount + reactive watch
 */
import { ref, onMounted, onUnmounted, watch } from 'vue'
import { readStored, writeStored } from '../utils/safeStorage'

const SIZES = {
    small:  '13px',
    medium: '16px',
    large:  '19px',
}
const DEFAULT_SIZE = 'medium'
const STORAGE_KEY = 'app_font_size'

// Global reactive state
const fontSize = ref(DEFAULT_SIZE)

// Initialize from localStorage (only on client).
// Via safeStorage because this read runs at IMPORT time: anything thrown here
// takes down every component that imports this module. See safeStorage.js.
if (typeof window !== 'undefined') {
    const saved = readStored(STORAGE_KEY)
    if (saved && SIZES[saved]) {
        fontSize.value = saved
    }
    // Apply ทันทีตอนโหลดเพื่อกัน flicker
    applyFontSize(fontSize.value)
}

/**
 * Apply font size — bypass CSS specificity ด้วย inline style ตรงที่ <html>
 * + ตั้ง data-attribute สำหรับ debugging/CSS hooks เพิ่มเติม
 */
function applyFontSize(size) {
    if (typeof document === 'undefined') return
    const px = SIZES[size] || SIZES[DEFAULT_SIZE]
    // 🔧 set ตรงที่ <html> element — Tailwind text-xs/sm/base ใช้ rem จะ scale ตามนี้
    document.documentElement.style.fontSize = px
    document.documentElement.setAttribute('data-font-size', size)
    // ตั้ง CSS variable เผื่อ component ที่อยากใช้ scale อื่น
    document.documentElement.style.setProperty('--app-font-base', px)
}

export function useFontSize() {
    const handler = (e) => {
        if (e.detail && SIZES[e.detail]) {
            fontSize.value = e.detail
        }
    }

    onMounted(() => {
        // ✅ Re-apply ตอน mount ในกรณี SSR/hydration mismatch
        applyFontSize(fontSize.value)
        window.addEventListener('font-size-change', handler)
    })
    onUnmounted(() => {
        window.removeEventListener('font-size-change', handler)
    })

    // Auto-apply ทุกครั้งที่ค่าเปลี่ยน (jik any component sets it)
    watch(fontSize, (val) => {
        applyFontSize(val)
        writeStored(STORAGE_KEY, val)
    })

    function setFontSize(size) {
        if (!SIZES[size]) return
        fontSize.value = size
        applyFontSize(size)
        writeStored(STORAGE_KEY, size)
        // Broadcast ให้ component อื่น sync state
        window.dispatchEvent(new CustomEvent('font-size-change', { detail: size }))
    }

    return {
        fontSize,
        setFontSize,
        SIZES: Object.keys(SIZES),  // ['small', 'medium', 'large']
    }
}
