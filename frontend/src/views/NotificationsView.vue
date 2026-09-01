<script setup lang="ts">
import { useI18n } from '@/composables/useI18n'
const { td } = useI18n()

/**
 * NotificationsView — full-page notification list (TASK-053 Phase 3).
 * Reads live state from useNotificationsStore(). Clicking an item marks
 * it read and navigates to its internal link.
 */
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import NavBarAction from '@/design-system/components/NavBarAction.vue'
import AppCard from '@/design-system/components/AppCard.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import { apiErrorMessage } from '@/utils/apiError'
import { useToastStore } from '@/stores/toast'
import { useNotificationsStore, type AppNotification } from '@/stores/notifications'
import { resolveNotificationLink } from '@/utils/notificationLink'

const router = useRouter()
const store = useNotificationsStore()
const toast = useToastStore()
const { items, loading } = storeToRefs(store)

// TASK-079 Phase 2 (UX audit): store.fetchList() has no catch of its own,
// so a failed load used to reject into the console and leave this page
// stuck on an empty state that reads as "you have no notifications" — the
// most misleading possible failure mode for a notifications screen.
// Caught here (not in the store) so the recovery UI stays with the view.
const errorMessage = ref('')

async function loadList(): Promise<void> {
  errorMessage.value = ''
  try {
    await store.fetchList()
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'โหลดการแจ้งเตือนไม่สำเร็จ')
  }
}

onMounted(loadList)

async function markAllRead(): Promise<void> {
  try {
    await store.markAllRead()
    toast.success('อ่านทั้งหมดแล้ว')
  } catch (e) {
    toast.error(apiErrorMessage(e, 'ทำเครื่องหมายว่าอ่านแล้วไม่สำเร็จ'))
  }
}

async function onItemClick(item: AppNotification) {
  try {
    await store.markRead(item.id)
  } catch (e) {
    // Navigation is the point of the tap — still honour it, but say why
    // the unread dot didn't clear instead of failing silently.
    toast.error(apiErrorMessage(e, 'ทำเครื่องหมายว่าอ่านแล้วไม่สำเร็จ'))
  }
  // Shared with NotificationBell — this view used to keep its own byte-
  // identical copy of the mapping, and the two drifted apart the moment
  // /announcements was added. See utils/notificationLink.ts.
  const target = resolveNotificationLink(item)
  if (target) router.push(target)
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('th-TH')
}
</script>

<template>
  <!-- TASK-079 Phase 4 (2026-08-03, UX audit): one of the 4 views migrated
       off the hand-rolled `px-4 py-4` + bare <h1> shell onto the shared
       HeroHeader shell, plus `back-page` — HeroHeader's back button had zero
       callers app-wide, so this page (not a BottomNav tab, usually reached
       from the bell icon) had no in-app way back. -->
  <main class="min-h-screen px-4 py-6 lg:px-8" style="font-family: var(--app-font);">
    <HeroHeader
      icon="bell"
      :title="td('notification.title2')"
      :subtitle="td('notification.subtitle')"
      accent-color="brand"
      storage-key="notifications"
      back-page="/"
      :back-label="td('nav.home2')"
    >
      <!-- TASK-087 — text form of the navigation-bar action (the iOS
           "Done"/"Edit" style); see NavBarAction.vue. -->
      <template #actions>
        <NavBarAction @click="markAllRead">{{ td('notification.mark_all_read2') }}</NavBarAction>
      </template>
    </HeroHeader>

    <!-- TASK-079 Phase 3 (UX audit finding D): skeleton → real content was a
         single-frame hard swap, which reads as a flash on a phone. .content-fade
         lives in assets/main.css (and is neutralised under
         prefers-reduced-motion). <Transition> takes exactly ONE child per
         branch, hence the wrapper <div>s — and this view must stay
         single-rooted or App.vue's <Transition mode="out-in"> around
         <RouterView> breaks (the multi-root Fragment regression). -->
    <Transition name="content-fade">
      <!-- TASK-079 Phase 2 (UX audit): failed loads used to be indistinguishable
           from "no notifications". Shown before the empty state, with retry. -->
      <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-surface-danger border border-line-card text-sm text-ink-danger flex items-center justify-between gap-3">
        <span>{{ errorMessage }}</span>
        <!-- Deliberately NOT an AppButton (TASK-079 Phase 4): this is the
             tinted-on-rose retry that sits INSIDE an error banner, a
             one-off treatment shared by 5 views. Neither `danger` (solid
             rose) nor `secondary` (white/slate) reads correctly on a rose
             background, and adding a variant for it would put a colour
             that only ever appears here into the primitive. -->
        <button
          type="button"
          class="shrink-0 min-h-[44px] px-3 py-2 rounded-lg text-xs font-bold text-ink-danger bg-rose-100 hover:bg-rose-200 active:scale-95 transition"
          @click="loadList"
        >
          {{ td('common.retry') }}
        </button>
      </div>

      <!-- Loading. TASK-079 Phase 4 (UX audit): was a hand-rolled stack of
           `animate-pulse` boxes; now the shared skeleton, so loading looks
           identical on every screen. -->
      <LoadingSkeleton v-else-if="loading && !items.length" type="list" :rows="5" />

      <!-- Empty -->
      <div
        v-else-if="!items.length"
        class="flex flex-col items-center justify-center gap-2 py-16 text-center"
      >
        <Icon name="bell" :size="40" class="text-ink-card-subtle" />
        <p class="text-sm text-ink-card-muted font-bold">{{ td('notification.empty2') }}</p>
      </div>

      <!-- List -->
      <!-- TASK-079 Phase 3 (UX audit): `hover:` never fires on a touchscreen,
             so tapping this card used to produce zero visual response and
             agents tapped it twice. `active:` is the touch equivalent. -->
      <div v-else class="space-y-2 mt-4">
        <button v-for="item in items" :key="item.id" type="button" class="w-full text-left" @click="onItemClick(item)">
          <!-- The unread treatment (left rule + tinted fill) is a per-item
               state override on top of the shared surface, so it stays a
               :class on the card rather than becoming an AppCard prop.

               Human-reported 2026-08-22 ("Ui สีก่อนคลิ๊กมีปัญหา"): it was
               `border-l-brand-500 bg-brand-50/60`, and `brand-50` is the
               lightest step of a lightness-generated ramp — permanently pale,
               whatever the tenant's card is. On a near-black card carrying
               light `--ink-card`, the unread row was the one row you could
               not read. ADR-023 §2.2, same class of breakage as the pale chip.

               `--surface-chip` is the card stepped 10% toward its own ink, so
               it tints in the right direction on either polarity; `--ink-brand`
               is the brand hue already walked to clear AA on this card. See
               NotificationBell.vue, which carried the identical pair. -->
          <AppCard
            interactive
            class="flex flex-col gap-1"
            :class="!item.is_read ? 'border-l-4 border-l-ink-brand bg-surface-chip' : ''"
          >
            <div class="flex items-center gap-2">
              <span
                class="text-[11px] font-bold px-2 py-0.5 rounded-full text-ink-chip"
                :class="item.is_read ? 'bg-surface-chip' : 'bg-surface-card'"
              >
                {{ item.type_label }}
              </span>
              <span v-if="!item.is_read" class="w-2 h-2 rounded-full bg-ink-brand shrink-0"></span>
              <span class="ml-auto text-[11px] text-ink-card-subtle">{{ formatDate(item.created_at) }}</span>
            </div>
            <p class="text-sm font-bold text-ink-card">{{ item.title }}</p>
            <p v-if="item.body" class="text-xs text-ink-card-muted">{{ item.body }}</p>
          </AppCard>
        </button>
      </div>
    </Transition>
  </main>
</template>
