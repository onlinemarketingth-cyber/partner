<script setup lang="ts">
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

function resolveLink(link: string): string {
  return link === '/news' ? '/' : link
}

async function onItemClick(item: AppNotification) {
  try {
    await store.markRead(item.id)
  } catch (e) {
    // Navigation is the point of the tap — still honour it, but say why
    // the unread dot didn't clear instead of failing silently.
    toast.error(apiErrorMessage(e, 'ทำเครื่องหมายว่าอ่านแล้วไม่สำเร็จ'))
  }
  if (item.link && item.link.startsWith('/')) {
    router.push(resolveLink(item.link))
  }
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
      title="การแจ้งเตือน"
      subtitle="ความเคลื่อนไหวล่าสุดของคุณ"
      accent-color="brand"
      storage-key="notifications"
      back-page="/"
      back-label="หน้าหลัก"
    >
      <!-- TASK-087 — text form of the navigation-bar action (the iOS
           "Done"/"Edit" style); see NavBarAction.vue. -->
      <template #actions>
        <NavBarAction @click="markAllRead">อ่านทั้งหมด</NavBarAction>
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
          ลองใหม่
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
        <p class="text-sm text-ink-card-muted font-bold">ยังไม่มีการแจ้งเตือน</p>
      </div>

      <!-- List -->
      <!-- TASK-079 Phase 3 (UX audit): `hover:` never fires on a touchscreen,
             so tapping this card used to produce zero visual response and
             agents tapped it twice. `active:` is the touch equivalent. -->
      <div v-else class="space-y-2 mt-4">
        <button v-for="item in items" :key="item.id" type="button" class="w-full text-left" @click="onItemClick(item)">
          <!-- The unread treatment (left rule + tinted fill) is a per-item
               state override on top of the shared surface, so it stays a
               :class on the card rather than becoming an AppCard prop. -->
          <AppCard
            interactive
            class="flex flex-col gap-1"
            :class="!item.is_read ? 'border-l-4 border-l-brand-500 bg-brand-50/60' : ''"
          >
            <div class="flex items-center gap-2">
              <span class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-surface-chip text-ink-card-muted">
                {{ item.type_label }}
              </span>
              <span v-if="!item.is_read" class="w-2 h-2 rounded-full bg-brand-500"></span>
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
