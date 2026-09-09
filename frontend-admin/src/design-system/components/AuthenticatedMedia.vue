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
    /**
     * 2026-09-09 (human: "หน้า frontend load รูปมาที่หลังประสบการณ์ไม่ดี
     * ค่อยทำให้ภาพชัดขึ้นเรื่อยๆ จนโหลดเสร็จได้หรือไม่").
     *
     * A ~20px base64 data URI of the same picture, from the API
     * (`placeholder` / `thumbnail_placeholder` — App\Support\Media\
     * ImageThumbnailer). It arrives with the list itself, so it needs no
     * request and no authorisation: it can be painted in the first frame.
     *
     * Optional everywhere on purpose. A caller that does not pass it, or
     * a row that has none (a video, a product with no photos, an image GD
     * could not read), gets exactly the grey box this component has always
     * shown.
     */
    placeholder?: string | null
  }>(),
  { type: 'image', controls: true, class: '', placeholder: null },
)

const sourceRef = toRef(props, 'src')
const { objectUrl, loading, error, retry } = useAuthenticatedMedia(sourceRef)

/*
 * ── THE BLUR-UP, IN ONE <img> ──
 *
 * The obvious build is two stacked elements that cross-fade. This is one
 * element whose `src` flips from the tiny data URI to the real blob and
 * whose CSS filter flips from blurred to sharp. The browser swaps the
 * pixels instantly (the data URI is already decoded) and the `filter`
 * transition then animates over the NEW pixels — which is precisely
 * "ค่อยทำให้ภาพชัดขึ้นเรื่อยๆ", and with no absolute positioning it
 * cannot disturb any caller's layout.
 *
 * Videos are excluded: a poster frame is a different question, and the
 * grey box is the right answer while one loads.
 */
const blurSrc = computed(() => (props.type === 'image' ? props.placeholder || null : null))
const displaySrc = computed(() => objectUrl.value ?? blurSrc.value)
const isBlurred = computed(() => !objectUrl.value)

/** The old grey box — now only when there is nothing at all to show. */
const showPlaceholder = computed(() => !props.src || !!error.value || (!objectUrl.value && !blurSrc.value))
</script>

<template>
  <!-- TASK-224 — the ERROR placeholder is a button, the other two are
       not. A failed media fetch used to be a dead red triangle that
       stayed until the component happened to remount; auto-retry now
       covers a blip, and this covers everything it deliberately does
       not (a 404 the admin has since re-uploaded, a 403 a re-login
       cleared). `type="button"` matters — this is rendered inside
       product forms, and a bare <button> would submit them. -->
  <button
    v-if="showPlaceholder && error"
    type="button"
    :title="error + ' — แตะเพื่อลองใหม่'"
    :class="['flex flex-col items-center justify-center gap-1 bg-slate-100 text-slate-300', props.class]"
    @click.stop.prevent="retry"
  >
    <Icon name="refresh" :size="20" class="text-rose-300" />
    <span class="text-[10px] font-bold">ลองใหม่</span>
  </button>
  <div v-else-if="showPlaceholder" :class="['flex items-center justify-center bg-slate-100 text-slate-300', props.class]">
    <Icon v-if="loading" name="clock" :size="20" class="animate-pulse" />
    <Icon v-else :name="type === 'video' ? 'play' : 'image'" :size="20" />
  </div>
  <video v-else-if="type === 'video'" :src="objectUrl!" :controls="controls" :class="props.class" />
  <img v-else :src="displaySrc!" :class="[props.class, isBlurred ? 'am-blur' : 'am-sharp']" />
</template>

<style scoped>
/*
 * The sharpening itself. `am-sharp` carries no filter of its own — it is
 * the absence of the blur, and the transition is what makes the removal
 * gradual rather than a jump.
 */
img {
  transition: filter 500ms ease-out;
}

.am-blur {
  filter: blur(12px);
}

/* Someone who has asked their system for less movement gets the picture,
   not the animation. */
@media (prefers-reduced-motion: reduce) {
  img {
    transition: none;
  }
}
</style>
