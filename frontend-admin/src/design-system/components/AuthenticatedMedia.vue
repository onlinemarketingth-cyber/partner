<script setup lang="ts">
/**
 * AuthenticatedMedia — renders an <img> or <video> whose source is a
 * Sanctum-protected stream/thumbnail URL (ADR-007: product media,
 * sales-material video, Academy module video). Wraps
 * useAuthenticatedMedia() in its own component instance so it can be
 * used freely inside v-for loops (composables with onUnmounted must
 * live in their own component setup, not a parent's loop body).
 */
import { computed, toRef } from 'vue'
import { useAuthenticatedMedia } from '@/composables/useAuthenticatedMedia'
import Icon from './Icon.vue'

const props = withDefaults(
  defineProps<{
    src: string | null
    type?: 'image' | 'video'
    controls?: boolean
    class?: string
  }>(),
  { type: 'image', controls: true, class: '' },
)

const sourceRef = toRef(props, 'src')
const { objectUrl, loading, error } = useAuthenticatedMedia(sourceRef)
const showPlaceholder = computed(() => !props.src || loading.value || error.value)
</script>

<template>
  <div v-if="showPlaceholder" :class="['flex items-center justify-center bg-slate-100 text-slate-300', props.class]">
    <Icon v-if="loading" name="clock" :size="20" class="animate-pulse" />
    <Icon v-else-if="error" name="alert" :size="20" class="text-rose-300" />
    <Icon v-else :name="type === 'video' ? 'play' : 'image'" :size="20" />
  </div>
  <video v-else-if="type === 'video'" :src="objectUrl!" :controls="controls" :class="props.class" />
  <img v-else :src="objectUrl!" :class="props.class" />
</template>
