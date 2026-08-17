<script setup lang="ts">
/**
 * NotificationBell — real implementation (TASK-053 Phase 3).
 *
 * Reads live state from useNotificationsStore(): unread badge on mount,
 * full list fetched lazily when the dropdown opens. Clicking an item
 * marks it read and navigates to its internal link. No business logic
 * here — the store owns transport, this is presentation only.
 *
 * ─────────────────────────────────────────────────────────────────────
 * TASK-098 / ADR-023 (2026-08-04) — colours now come from the
 * surface/ink/line tokens instead of hardcoded slate/white utilities.
 *
 * This component straddles two surfaces, and that is exactly why
 * ADR-023 §2.2 lists it among the confirmed breakages:
 *   - the BELL BUTTON lives in App.vue's / TopNavigation's top bar, i.e.
 *     on nav chrome → `text-ink-nav` / `text-ink-nav-muted`.
 *   - the DROPDOWN is a popover, a card-family surface → `bg-surface-card`
 *     + the card ink scale + `border-line-card`.
 * As a literal `bg-surface-card` popover it was outside the theme entirely
 * (ADR-023 §2.1), so on a black-card tenant it opened as a stark white
 * sheet; and its `bg-surface-chip text-ink-card-muted` type pill was the
 * light-on-light case. Both are now derived pairs.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import Icon from './Icon.vue'
import { useNotificationsStore, type AppNotification } from '@/stores/notifications'

const router = useRouter()
const store = useNotificationsStore()
const { items, unreadCount, loading } = storeToRefs(store)

const open = ref(false)
const root = ref<HTMLElement | null>(null)

function toggle() {
  open.value = !open.value
  if (open.value) {
    store.fetchList()
  }
}

// Map an internal link to a routable path. '/news' has no dedicated
// page in this frontend — it lives on the home hub, so redirect there.
function resolveLink(link: string): string {
  return link === '/news' ? '/' : link
}

async function onItemClick(item: AppNotification) {
  await store.markRead(item.id)
  open.value = false
  if (item.link && item.link.startsWith('/')) {
    router.push(resolveLink(item.link))
  }
}

function onMarkAll() {
  store.markAllRead()
}

function onViewAll() {
  open.value = false
  router.push('/notifications')
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('th-TH')
}

// Close on outside click.
function onDocClick(e: MouseEvent) {
  if (open.value && root.value && !root.value.contains(e.target as Node)) {
    open.value = false
  }
}

onMounted(() => {
  store.fetchUnreadCount()
  document.addEventListener('click', onDocClick)
})
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))
</script>

<template>
  <div ref="root" class="relative">
    <!-- TASK-087 — Apple HIG minimum tap target is 44x44pt. This hit box
         was 40x40, i.e. under it, and it sits in the top bar next to two
         other controls where a mis-tap opens the wrong thing. The bell
         GLYPH stays 20px; only the transparent hit box grows. -->
    <button
      type="button"
      @click="toggle"
      class="relative inline-flex items-center justify-center w-11 h-11 rounded-xl hover:bg-surface-chip transition-colors"
      :class="{ 'bg-surface-chip': open }"
      title="การแจ้งเตือน"
    >
      <!-- Nav chrome: the glyph takes the NAV ink pair, not the card's. -->
      <Icon name="bell" :size="20" :class="open ? 'text-ink-nav' : 'text-ink-nav-muted'" />
      <!-- Unread dot keeps `text-white`: `bg-rose-500` is a FIXED Tailwind
           ramp step no tenant can repaint, so white is provably readable
           on it and no derived token applies. -->
      <span
        v-if="unreadCount > 0"
        class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-rose-500 text-white text-[11px] font-bold flex items-center justify-center shadow-md"
      >
        {{ unreadCount > 99 ? '99+' : unreadCount }}
      </span>
    </button>

    <div
      v-if="open"
      class="absolute right-0 mt-2 w-80 max-w-[calc(100vw-2rem)] rounded-2xl bg-surface-card border border-line-card shadow-2xl overflow-hidden z-50"
    >
      <div class="px-5 py-3.5 border-b border-line-card-subtle flex items-center gap-2">
        <Icon name="bell" :size="16" class="text-ink-card-muted" />
        <h3 class="font-bold text-ink-card text-sm">การแจ้งเตือน</h3>
        <button
          type="button"
          @click="onMarkAll"
          class="ml-auto text-xs font-bold text-ink-brand hover:opacity-80"
        >
          อ่านทั้งหมด
        </button>
      </div>

      <!-- Loading -->
      <div v-if="loading && !items.length" class="px-5 py-10 text-center text-ink-card-subtle text-sm">
        กำลังโหลด…
      </div>

      <!-- Empty -->
      <div v-else-if="!items.length" class="px-5 py-10 text-center text-ink-card-subtle text-sm">
        ยังไม่มีการแจ้งเตือน
      </div>

      <!-- List -->
      <div v-else class="max-h-96 overflow-y-auto divide-y divide-line-card-subtle">
        <button
          v-for="item in items"
          :key="item.id"
          type="button"
          @click="onItemClick(item)"
          class="w-full text-left px-5 py-3 hover:bg-surface-chip transition-colors flex flex-col gap-1"
          :class="!item.is_read ? 'bg-brand-50 border-l-2 border-brand-500' : ''"
        >
          <div class="flex items-center gap-2">
            <!-- The type pill is ADR-023 §2.2's exact example: as
                 `bg-surface-chip text-ink-card-muted` it kept a pale background
                 while the card override repainted its text light. -->
            <span class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-surface-chip text-ink-chip">
              {{ item.type_label }}
            </span>
            <span class="ml-auto text-[11px] text-ink-card-subtle">{{ formatDate(item.created_at) }}</span>
          </div>
          <p class="text-sm font-bold text-ink-card">{{ item.title }}</p>
          <p v-if="item.body" class="text-xs text-ink-card-muted line-clamp-2">{{ item.body }}</p>
        </button>
      </div>

      <div class="px-5 py-2.5 border-t border-line-card-subtle text-center">
        <button
          type="button"
          @click="onViewAll"
          class="text-xs font-bold text-ink-brand hover:opacity-80"
        >
          ดูทั้งหมด
        </button>
      </div>
    </div>
  </div>
</template>
