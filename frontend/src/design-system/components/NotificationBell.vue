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
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from '@/composables/useI18n'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import Icon from './Icon.vue'
import { useNotificationsStore, type AppNotification } from '@/stores/notifications'
import { resolveNotificationLink } from '@/utils/notificationLink'
import { useToastStore } from '@/stores/toast'
import { apiErrorMessage } from '@/utils/apiError'
import {
  isNotificationSoundMuted,
  playNotificationSound,
  setNotificationSoundMuted,
} from '@/utils/notificationSound'

const router = useRouter()
const store = useNotificationsStore()
const { td } = useI18n()
const toast = useToastStore()
const { items, unreadCount, arrivals, loading } = storeToRefs(store)

const open = ref(false)
const root = ref<HTMLElement | null>(null)

/**
 * The chime (human request 2026-08-22, "เพิ่มเสียงการแจ้งเตือน").
 *
 * Driven by `arrivals`, not by `unreadCount`. The two diverge constantly: the
 * agent reads something on their phone and the unread count DROPS, then a new
 * notification brings it back to where it was — a watcher on the count would
 * hear no change and stay silent for a notification that really did arrive.
 * `arrivals` only ever counts up, and only for things this session has not
 * seen before.
 *
 * The store deliberately does not play the sound itself: a store that makes
 * noise is a store that makes noise in unit tests, in SSR, and in whatever
 * else imports it later. The component that owns the bell owns the bell's
 * sound.
 */
const soundMuted = ref(isNotificationSoundMuted())

watch(arrivals, (now, before) => {
  if (now > (before ?? 0)) playNotificationSound()
})

function toggleSound(event: MouseEvent) {
  // The dropdown closes on any outside click; this control lives inside it
  // and must not also count as one.
  event.stopPropagation()
  soundMuted.value = !soundMuted.value
  setNotificationSoundMuted(soundMuted.value)

  // Pressing "unmute" is itself the user gesture browsers require before
  // audio may play, so this doubles as a preview AND as the moment the
  // AudioContext is allowed to start.
  if (!soundMuted.value) playNotificationSound()
}

function toggle() {
  open.value = !open.value
  if (open.value) {
    store.fetchList()
  }
}

/**
 * Tapping an item marks it read and, IF it has somewhere to go, navigates.
 *
 * Two things here are deliberate and were the reported bug (2026-08-22,
 * "คลิ๊ก Noti แล้วไม่ไปไหน"):
 *
 * 1. The destination now comes from utils/notificationLink.ts, which knows
 *    that announcements live on /announcements — the old local copy still
 *    pointed at '/', so tapping a news item while standing on the home page
 *    closed the panel and moved nothing.
 *
 * 2. The panel only closes when we are ACTUALLY navigating. An item with no
 *    destination (an account-status change, whose whole content is its own
 *    body text) used to close the dropdown and do nothing, which reads as a
 *    broken tap. Left open, the row flips out of its unread treatment under
 *    the finger — a real, visible response to a real, visible action.
 *
 * markRead is awaited but its failure must not swallow the navigation: the
 * tap's purpose is to GO somewhere. An unread dot that fails to clear is
 * worth a toast, not a dead end.
 */
async function onItemClick(item: AppNotification) {
  try {
    await store.markRead(item.id)
  } catch (e) {
    toast.error(apiErrorMessage(e, td('notification.mark_read_failed')))
  }

  const target = resolveNotificationLink(item)
  if (!target) return

  open.value = false
  router.push(target)
}

function onMarkAll() {
  store.markAllRead()
}

function onViewAll() {
  open.value = false
  // Already on the list? push() would resolve to the same route and do
  // nothing, so the only feedback would be the panel disappearing.
  if (router.currentRoute.value.path !== '/notifications') {
    router.push('/notifications')
  }
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
      :title="td('notification.title')"
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
        <h3 class="font-bold text-ink-card text-sm">{{ td('notification.title') }}</h3>
        <!-- A sound the user cannot stop is worse than no sound: an agent
             working with the portal open in a quiet office would just close
             the tab. Kept next to the bell rather than buried in Profile,
             because the moment somebody wants it off is the moment it just
             went off. -->
        <button
          type="button"
          @click="toggleSound"
          class="ml-auto w-9 h-9 -my-1 inline-flex items-center justify-center rounded-lg hover:bg-surface-chip transition-colors"
          :title="soundMuted ? td('notification.sound_on') : td('notification.sound_off')"
          :aria-label="soundMuted ? td('notification.sound_on') : td('notification.sound_off')"
          :aria-pressed="soundMuted"
        >
          <Icon
            :name="soundMuted ? 'volume_off' : 'volume_on'"
            :size="16"
            :class="soundMuted ? 'text-ink-card-subtle' : 'text-ink-brand'"
          />
        </button>
        <button
          type="button"
          @click="onMarkAll"
          class="text-xs font-bold text-ink-brand hover:opacity-80"
        >
          {{ td('notification.mark_all_read') }}
        </button>
      </div>

      <!-- Loading -->
      <div v-if="loading && !items.length" class="px-5 py-10 text-center text-ink-card-subtle text-sm">
        {{ td('common.loading') }}
      </div>

      <!-- Empty -->
      <div v-else-if="!items.length" class="px-5 py-10 text-center text-ink-card-subtle text-sm">
        {{ td('notification.empty') }}
      </div>

      <!-- List -->
      <div v-else class="max-h-96 overflow-y-auto divide-y divide-line-card-subtle">
        <button
          v-for="item in items"
          :key="item.id"
          type="button"
          @click="onItemClick(item)"
          class="w-full text-left px-5 py-3 transition-colors flex flex-col gap-1"
          :class="item.is_read
            ? 'hover:bg-surface-chip'
            : 'bg-surface-chip border-l-2 border-ink-brand'"
        >
          <!-- ── THE UNREAD TREATMENT (human-reported 2026-08-22, "Ui สีก่อน
               คลิ๊กมีปัญหา") ──

               It was `bg-brand-50 border-l-2 border-brand-500`, and both
               halves are ADR-023 §2.2's failure verbatim, one level deeper
               than TASK-098 reached when it fixed this same file's popover
               and type pill.

               `brand-50` is generated by applyRamp() as the LIGHTEST
               lightness-mix of primary_hex, so it is pale on every tenant
               that will ever exist. `--surface-card` is whatever the admin
               chose, near-black here, and `--ink-card` is derived LIGHT to
               suit it. So the unread row — the one the reader is meant to
               notice first — painted itself pale and then wrote light text
               on it. Unread was the least readable state on the screen.

               `--surface-chip` is the card stepped 10% toward its own ink:
               a light tint on a light card, a lighter dark on a dark one.
               It moves in whichever direction the tenant needs, and
               `--ink-card` is AA against the card, so it stays AA against a
               10% step off it.

               The left rule is `--ink-brand`, the brand hue already walked
               toward whichever pole clears AA on this card — a fixed ramp
               step vanishes into a black card exactly like the price did in
               ProductCard.

               `hover:` moves to the READ branch only: an unread row is
               already sitting on the hover colour, so keeping it there would
               have made hover a no-op on precisely those rows. -->
          <div class="flex items-center gap-2">
            <!-- The type pill is ADR-023 §2.2's exact example: as
                 `bg-surface-chip text-ink-card-muted` it kept a pale background
                 while the card override repainted its text light.
                 On an unread row it now sits on surface-chip too, so it takes
                 the card surface instead to stay a distinct pill. -->
            <span
              class="text-[11px] font-bold px-2 py-0.5 rounded-full text-ink-chip"
              :class="item.is_read ? 'bg-surface-chip' : 'bg-surface-card'"
            >
              {{ item.type_label }}
            </span>
            <!-- Unread is not signalled by colour ALONE. A tinted row is
                 invisible to a reader who cannot separate the two tints —
                 and, more mundanely, to anyone glancing at a phone in
                 sunlight. NotificationsView already carried this dot. -->
            <span v-if="!item.is_read" class="w-2 h-2 rounded-full bg-ink-brand shrink-0"></span>
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
          {{ td('notification.view_all') }}
        </button>
      </div>
    </div>
  </div>
</template>
