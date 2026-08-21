<script setup lang="ts">
/**
 * A Thai national ID typed the way the card is printed: five boxes, one per
 * printed group, advancing on their own.
 *
 * ── WHY BOXES INSTEAD OF ONE FIELD ──
 *
 * The card prints 1 2345 67890 12 1, and thirteen unbroken digits in a
 * single box cannot be checked against the card without counting them.
 * People re-read the same number three times and still mistype it, and a
 * mod-11 checksum means a single wrong digit is rejected with no clue as to
 * WHICH digit — so a form that makes the mistake hard to see is a form that
 * makes the error message useless. The groups exist on the card precisely so
 * a human can hold their place; the field now has them too.
 *
 * ── WHAT THIS COMPONENT REFUSES TO DO ──
 *
 * It does not check the checksum, and it must not learn to. That lives in
 * App\Rules\ThaiNationalId, once, on the server, next to the blind index
 * that depends on a canonical value. A copy here would look like a
 * kindness and behave like a second source of truth: the two drift, and the
 * form starts either accepting numbers the server rejects or rejecting
 * numbers it would have taken. Both failures are silent.
 *
 * What it DOES own is that the value leaving it is always bare digits, at
 * most thirteen — the exact shape the server calls canonical.
 *
 * ── THE PARTS THAT LOOK FUSSY AND ARE NOT ──
 *
 * Auto-advance without auto-RETREAT is a trap: backspace at the start of an
 * empty box has to step back, or a correction becomes "clear everything and
 * start again". Same for arrow keys, and for a paste of the whole number
 * into any box — people paste from a note or a chat message, and a paste
 * that only fills the box it landed in silently truncates twelve digits.
 *
 * Each box is `inputmode="numeric"`, which is what raises the phone keypad;
 * `type="text"` rather than `type="number"`, because a number input drops
 * leading zeros, offers spinners, and accepts "e" and "-".
 */
import { computed, nextTick, ref, watch } from 'vue'

const props = defineProps<{
  /** The whole number, bare digits. '' when nothing has been typed. */
  modelValue: string
  /** Marks every box, so a rejected number is visibly one number. */
  invalid?: boolean
  /** id of the FIRST box, so an external <label for> still lands somewhere. */
  id?: string
  ariaLabel?: string
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: string): void
  /** Fired when the thirteenth digit lands — the caller may then check it. */
  (e: 'complete', value: string): void
}>()

/**
 * 1-4-5-2-1. These are the groups printed on the card, not an arbitrary
 * split, and they sum to 13 — which is asserted below rather than trusted,
 * because a typo here would silently cap the number at the wrong length.
 */
const GROUP_SIZES = [1, 4, 5, 2, 1]
const TOTAL = GROUP_SIZES.reduce((a, b) => a + b, 0)

if (TOTAL !== 13) {
  throw new Error(`NationalIdSegments: groups must sum to 13, got ${TOTAL}`)
}

/**
 * size + where the group starts in the 13-digit string, resolved once.
 * Carried together rather than as two parallel arrays so nothing downstream
 * has to index into a second list and reason about whether it lines up.
 */
const GROUPS = GROUP_SIZES.map((size, index) => ({
  size,
  offset: GROUP_SIZES.slice(0, index).reduce((a, b) => a + b, 0),
}))

const LAST = GROUPS.length - 1

const boxes = ref<HTMLInputElement[]>([])

function setBoxRef(el: unknown, index: number): void {
  if (el instanceof HTMLInputElement) boxes.value[index] = el
}

/** The model, sliced into the printed groups. */
const parts = computed(() =>
  GROUPS.map(({ size, offset }) => (props.modelValue ?? '').slice(offset, offset + size)),
)

function digitsOnly(value: string): string {
  return value.replace(/\D/g, '')
}

/** Publish a new whole number, and say so when it reaches thirteen digits. */
function emitValue(next: string): void {
  emit('update:modelValue', next)

  if (next.length === TOTAL) emit('complete', next)
}

/**
 * Rebuild the whole number from one group's new contents and publish it.
 * Always goes through the parent so the model stays the single source of
 * truth — the boxes render from it, they do not hold state of their own.
 */
function publish(index: number, groupValue: string): void {
  emitValue(parts.value.map((part, i) => (i === index ? groupValue : part)).join(''))
}

/** The group a caret should sit in once `length` digits have been entered. */
function groupHolding(length: number): number {
  const found = GROUPS.findIndex(({ size, offset }) => length < offset + size)

  return found === -1 ? LAST : found
}

async function focusBox(index: number, caret: 'start' | 'end' = 'end'): Promise<void> {
  if (index < 0 || index >= GROUPS.length) return

  await nextTick()
  const el = boxes.value[index]
  if (!el) return

  el.focus()
  const at = caret === 'start' ? 0 : el.value.length
  // Guard: setSelectionRange throws on input types that do not support it,
  // and jsdom is stricter than browsers about it.
  try {
    el.setSelectionRange(at, at)
  } catch {
    // Focus is the part that matters; the caret is a nicety.
  }
}

async function onInput(index: number, event: Event): Promise<void> {
  const group = GROUPS[index]
  if (!group) return

  const el = event.target as HTMLInputElement
  const typed = digitsOnly(el.value)

  // Typing past the end of a group SPILLS into the next ones rather than
  // being dropped. That covers the case that actually happens: somebody
  // types all thirteen digits without pausing, or a browser autofills the
  // whole number into one box.
  if (typed.length > group.size) {
    const rebuilt = ((props.modelValue ?? '').slice(0, group.offset) + typed).slice(0, TOTAL)
    emitValue(rebuilt)
    await focusBox(groupHolding(rebuilt.length))

    return
  }

  const value = typed.slice(0, group.size)
  // Write the cleaned value straight back to the DOM node. Vue will not
  // re-render a box whose bound value did not change, so a rejected
  // character (a letter, a dash) would otherwise sit visible in the box
  // until the next keystroke.
  el.value = value
  publish(index, value)

  if (value.length === group.size) await focusBox(index + 1, 'start')
}

async function onKeydown(index: number, event: KeyboardEvent): Promise<void> {
  const el = event.target as HTMLInputElement
  const caretAtStart = (el.selectionStart ?? 0) === 0 && (el.selectionEnd ?? 0) === 0

  // Backspace in an EMPTY box steps back and deletes there. Without this,
  // fixing the first digit of a group means clearing the rest by hand.
  if (event.key === 'Backspace' && (el.value === '' || caretAtStart) && index > 0) {
    event.preventDefault()
    publish(index - 1, (parts.value[index - 1] ?? '').slice(0, -1))
    await focusBox(index - 1)

    return
  }

  if (event.key === 'ArrowLeft' && caretAtStart && index > 0) {
    event.preventDefault()
    await focusBox(index - 1)

    return
  }

  const caretAtEnd = (el.selectionStart ?? 0) === el.value.length
  if (event.key === 'ArrowRight' && caretAtEnd && index < LAST) {
    event.preventDefault()
    await focusBox(index + 1, 'start')
  }
}

/**
 * A paste of the whole number into ANY box fills the number from the start,
 * not from that box. Somebody pasting 1101700230708 into the third group
 * means "here is my ID", never "here are groups three onward".
 */
async function onPaste(event: ClipboardEvent): Promise<void> {
  const pasted = digitsOnly(event.clipboardData?.getData('text') ?? '')
  if (!pasted) return

  event.preventDefault()
  const next = pasted.slice(0, TOTAL)
  emitValue(next)
  await focusBox(groupHolding(next.length))
}

/** Cleared from outside (the document type changed) — go back to the start. */
watch(
  () => props.modelValue,
  (value, previous) => {
    if (value === '' && previous !== '') void focusBox(0, 'start')
  },
)

defineExpose({
  focus: () => focusBox(0, 'start'),
})
</script>

<template>
  <div
    class="flex items-center gap-1.5"
    role="group"
    :aria-label="ariaLabel ?? 'เลขบัตรประชาชน 13 หลัก'"
  >
    <template v-for="(group, index) in GROUPS" :key="index">
      <!-- The printed separator, drawn between groups. Decorative only:
           aria-hidden so a screen reader announces one number, not four
           stray dashes. -->
      <span v-if="index > 0" aria-hidden="true" class="text-ink-card-subtle select-none">-</span>

      <!-- Each box is as wide as its group is long, so the field is shaped
           like the card rather than five equal squares. That is flex-grow in
           proportion to the digit count over a zero basis — a style binding
           because Tailwind has no class for "grow by a number decided at
           runtime". The two single-digit groups are fixed width instead: at
           1/13th of the row they would be too narrow to read. -->
      <input
        :id="index === 0 ? id : undefined"
        :ref="(el) => setBoxRef(el, index)"
        :value="parts[index]"
        type="text"
        inputmode="numeric"
        autocomplete="off"
        spellcheck="false"
        :maxlength="group.size"
        :aria-label="`เลขบัตรประชาชน กลุ่มที่ ${index + 1} จาก ${GROUPS.length}`"
        :aria-invalid="invalid ? 'true' : undefined"
        class="bg-surface-input min-h-[44px] rounded-xl border px-1 py-2.5 text-center text-sm text-ink-input focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 transition-colors"
        :class="[
          invalid ? 'border-rose-400' : 'border-line-input',
          group.size === 1 ? 'w-9 shrink-0' : 'min-w-0',
        ]"
        :style="group.size === 1 ? undefined : { flexGrow: group.size, flexBasis: 0 }"
        @input="onInput(index, $event)"
        @keydown="onKeydown(index, $event)"
        @paste="onPaste"
      />
    </template>
  </div>
</template>
