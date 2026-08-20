<script setup lang="ts">
/**
 * CompanySwitcher — TASK-208 / ADR-038. The single "which company am I
 * working in" control, mounted once in AdminNavigation so it is on screen on
 * every page.
 *
 * Human, 2026-08-19: "ในฐานะ Super Admin ผมแยกไม่ออกเลยกำลังแก้สินค้าจาก
 * บริษัทไหน ... และแสดงชื่อบริษัทจะได้ทำงานได้ถูกต้อง".
 *
 * Renders for Super Admin only. A Company Admin has exactly one company and
 * TenantScope already pins every query to it, so a picker would be a control
 * that cannot do anything — they get a plain read-only label instead (their
 * own company name), which still answers "whose data am I looking at".
 *
 * "ทุกบริษัท" is a deliberate, visually distinct state (amber, not brand
 * navy): it is a read-across view where creating is blocked, so it should not
 * look like a normal working scope.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import Icon from './Icon.vue'
import { useActiveCompanyStore } from '@/stores/activeCompany'

const store = useActiveCompanyStore()

const open = ref(false)
const root = ref<HTMLElement | null>(null)
const search = ref('')

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()

  return q === '' ? store.companies : store.companies.filter((c) => c.name.toLowerCase().includes(q))
})

const label = computed(() => store.companyName ?? 'ทุกบริษัท')

function pick(id: number | null): void {
  store.setCompany(id)
  open.value = false
  search.value = ''
}

function onDocumentClick(event: MouseEvent): void {
  if (!open.value) return
  if (root.value && !root.value.contains(event.target as Node)) open.value = false
}
function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') open.value = false
}

onMounted(() => {
  store.loadCompanies()
  document.addEventListener('click', onDocumentClick)
  document.addEventListener('keydown', onKeydown)
})
onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick)
  document.removeEventListener('keydown', onKeydown)
})

// The list is only fetched for a Super Admin, and the store is created before
// /me resolves on a cold load — retry once the role is known.
watch(() => store.isSuperAdmin, (isSuper) => {
  if (isSuper) store.loadCompanies()
})
</script>

<template>
  <!-- Company Admin: identity only, nothing to switch. -->
  <div
    v-if="!store.isSuperAdmin"
    v-show="store.companyName"
    class="hidden sm:flex items-center gap-1.5 h-9 px-3 rounded-xl bg-slate-50 border border-slate-200 text-xs font-bold text-slate-600 max-w-[220px]"
  >
    <Icon name="building" :size="14" class="shrink-0 text-slate-400" />
    <span class="truncate">{{ store.companyName }}</span>
  </div>

  <div v-else ref="root" class="relative">
    <button
      type="button"
      class="flex items-center gap-1.5 h-9 px-3 rounded-xl border-2 text-xs font-bold transition-colors max-w-[240px]"
      :class="store.isAllCompanies
        ? 'bg-amber-50 border-amber-400 text-amber-700 hover:bg-amber-100'
        : 'bg-brand-50 border-brand-600 text-brand-700 hover:bg-brand-100'"
      :title="store.isAllCompanies ? 'กำลังดูข้ามทุกบริษัท — สร้างข้อมูลใหม่ไม่ได้' : `กำลังทำงานในบริษัท ${label}`"
      @click="open = !open"
    >
      <Icon name="building" :size="14" class="shrink-0" />
      <span class="truncate">{{ label }}</span>
      <svg
        class="w-3 h-3 shrink-0 transition-transform" :class="open ? 'rotate-180' : ''"
        viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"
        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"
      >
        <path d="M5 7.5L10 12.5L15 7.5" />
      </svg>
    </button>

    <div
      v-if="open"
      class="absolute right-0 z-[60] mt-1 w-[260px] max-h-[320px] overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-2xl"
    >
      <div class="sticky top-0 bg-white border-b border-slate-100 p-2">
        <input
          v-model="search"
          type="text"
          placeholder="ค้นหาบริษัท..."
          class="w-full h-8 px-2.5 rounded-lg border border-slate-200 text-xs"
        />
      </div>

      <button
        type="button"
        class="w-full text-left px-3 py-2 text-xs font-bold hover:bg-amber-50 flex items-center gap-2"
        :class="store.isAllCompanies ? 'bg-amber-50 text-amber-700' : 'text-slate-600'"
        @click="pick(null)"
      >
        <Icon name="layers" :size="14" class="shrink-0" />
        ทุกบริษัท (ดูอย่างเดียว)
      </button>

      <div class="h-px bg-slate-100"></div>

      <p v-if="store.loadError" class="px-3 py-2 text-[11px] font-bold text-rose-600">{{ store.loadError }}</p>
      <p v-else-if="!store.companies.length" class="px-3 py-2 text-[11px] text-slate-400">กำลังโหลด...</p>

      <button
        v-for="c in filtered"
        :key="c.id"
        type="button"
        class="w-full text-left px-3 py-2 text-xs font-bold hover:bg-brand-50 flex items-center gap-2"
        :class="store.selectedId === c.id ? 'bg-brand-50 text-brand-700' : 'text-slate-600'"
        @click="pick(c.id)"
      >
        <Icon name="building" :size="14" class="shrink-0 text-slate-400" />
        <span class="truncate">{{ c.name }}</span>
        <Icon v-if="store.selectedId === c.id" name="check" :size="14" class="ml-auto shrink-0 text-brand-600" />
      </button>
    </div>
  </div>
</template>
