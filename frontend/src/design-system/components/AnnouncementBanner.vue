<script setup lang="ts">
/**
 * AnnouncementBanner — TASK-080 (2026-08-03): renders announcements as
 * an inline 16:9 banner carousel, in ADDITION to the existing auto-popup
 * AnnouncementModal. Used on Home, สินค้า (storefront) and ข่าวสารทั้งหมด.
 *
 * The markup/classes are deliberately the SAME ones the storefront
 * banner carousel in ProductBrowseView.vue uses (aspect-[16/9],
 * horizontal snap-scroll, gradient title overlay) so an agent reads the
 * two as one component family rather than two competing banner styles.
 *
 * Purely presentational: the caller owns fetching, filtering (see
 * utils/announcementBanners.ts) and what a tap does — this only emits
 * `select` so the caller can open the same AnnouncementModal + record
 * the view that a news-card tap already does.
 */
import Icon from './Icon.vue'
import type { BannerAwareAnnouncement } from '@/utils/announcementBanners'

defineProps<{ items: BannerAwareAnnouncement[] }>()
const emit = defineEmits<{ (e: 'select', announcement: BannerAwareAnnouncement): void }>()
</script>

<template>
  <!-- TASK-098 / ADR-023: the tile frame and the text-only fallback take
       their colours from the surface/ink token layer (`border-line-card`,
       `bg-surface-chip`, `text-ink-primary`) instead of hardcoded slate and
       `text-white`. -->
  <!-- Render nothing at all when there's nothing to show — no empty row,
       no placeholder: banners are optional admin content (same treatment
       as the storefront carousel, DoD §9). -->
  <div v-if="items.length" class="flex gap-3 overflow-x-auto no-scrollbar snap-x snap-mandatory pb-1">
    <button
      v-for="item in items"
      :key="item.id"
      type="button"
      class="relative shrink-0 w-full aspect-[16/9] snap-start rounded-2xl overflow-hidden border border-line-card bg-surface-chip text-left active:scale-[0.98] transition-transform"
      @click="emit('select', item)"
    >
      <img v-if="item.image_url" :src="item.image_url" :alt="item.title" class="w-full h-full object-cover" />

      <!-- Text-only fallback. An announcement is NOT required to have an
           image (unlike a storefront banner, which is an image by
           nature), so an image-less one must still work as a banner —
           never a broken <img>, and never silently dropped, or an admin
           who ticked "show as banner" would see nothing appear. -->
      <div
        v-else
        class="w-full h-full flex flex-col items-center justify-center gap-2 px-5 text-center bg-brand-600 text-ink-primary"
      >
        <Icon name="megaphone" :size="24" class="opacity-80" />
        <p class="text-sm font-bold leading-snug line-clamp-3">{{ item.title }}</p>
        <span v-if="item.is_pinned" class="text-[11px] font-bold opacity-80">ปักหมุด</span>
      </div>

      <!-- Title overlay only on image banners — the fallback tile above
           already shows the title as its own content.
           TASK-098 / ADR-023: this `text-white` deliberately stays. Its
           background is the black gradient scrim over an arbitrary admin
           image, not a themed surface — white is the only ink guaranteed
           readable there, whatever the tenant's palette. -->
      <div
        v-if="item.image_url"
        class="absolute inset-x-0 bottom-0 px-3 py-2 bg-gradient-to-t from-black/60 to-transparent"
      >
        <p class="text-xs font-bold text-white truncate">{{ item.title }}</p>
      </div>
    </button>
  </div>
</template>

<style scoped>
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>
