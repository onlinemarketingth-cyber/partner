<script setup lang="ts">
/**
 * RichTextEditor — 2026-09-09 (human: "ตัวไหนที่เป็นคำอธิบาย หรือ ต้องการกรอก
 * ข้อความยาวๆ ให้เป็น text editor ในการจัดการแทนทั้งหมด").
 *
 * A plain <textarea> for a product description meant every list was typed as
 * "- " and every heading as a line in capitals, and the storefront rendered
 * all of it as one grey block held together by `whitespace-pre-line`.
 *
 * ── THE TOOLBAR IS THE WHOLE SPECIFICATION ──
 *
 * Bold, italic, underline, strikethrough, H2, H3, bullet list, numbered list,
 * link (human: "ชุดปุ่มพอไหม — พอ"). No colours, no fonts, no sizes, no
 * tables, no images. That is not a first cut to be extended later: the same
 * nine marks are the server's allowlist (App\Support\RichText), so anything
 * added here that is not added there is a button that silently loses the
 * admin's formatting on save. The two lists are one decision written twice.
 *
 * ── EMPTY MEANS EMPTY ──
 *
 * Tiptap's document is never truly empty — it always holds at least one
 * paragraph, so `editor.getHTML()` returns "<p></p>" for a blank editor.
 * Emitted as-is that string is TRUTHY, and every `v-if="product.description"`
 * in both apps would render a section containing one blank line, forever,
 * with no way to clear it. So blank is emitted as ''. The server maps it to
 * NULL; this keeps the two ends agreeing.
 *
 * ── EXISTING PLAIN TEXT ──
 *
 * Every row in these columns today is plain text, often with real line breaks.
 * Handed to Tiptap as HTML those breaks would collapse into one paragraph and
 * the admin would watch their formatting disappear on first open. `asHtml()`
 * converts a plain-text value on the way in, and only a value that is plainly
 * not markup, so re-opening a saved rich value is untouched.
 */
import { onBeforeUnmount, ref, watch } from 'vue'
import { EditorContent, useEditor } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Icon from './Icon.vue'

const props = withDefaults(defineProps<{
  modelValue: string | null
  placeholder?: string
  /** Rows-equivalent minimum height, in px. */
  minHeight?: number
  disabled?: boolean
}>(), {
  placeholder: '',
  minHeight: 180,
  disabled: false,
})

const emit = defineEmits<{ 'update:modelValue': [string] }>()

/**
 * Does this value already carry markup we produced?
 *
 * Deliberately narrow — it looks for the block/mark tags this editor emits,
 * not for "<" — so a description containing "ราคา < 5,000" is still treated as
 * the plain text it is, rather than being handed to the HTML parser where the
 * "<" would eat the rest of the sentence.
 */
function looksLikeHtml(value: string): boolean {
  return /<(p|br|strong|em|u|s|ul|ol|li|h2|h3|a)\b[^>]*>/i.test(value)
}

const escapeHtml = (value: string) =>
  value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')

/** Plain text in, paragraphs out — blank lines separate, single ones break. */
function asHtml(value: string | null): string {
  if (!value) return ''
  if (looksLikeHtml(value)) return value

  return value
    .split(/\n{2,}/)
    .map((block) => `<p>${escapeHtml(block).replace(/\n/g, '<br>')}</p>`)
    .join('')
}

const editor = useEditor({
  content: asHtml(props.modelValue),
  editable: !props.disabled,
  extensions: [
    StarterKit.configure({
      // Not on the toolbar, so not in the document: a mark with no button is
      // a mark the admin cannot remove once it arrives from a paste, and one
      // the server would strip on save anyway.
      blockquote: false,
      code: false,
      codeBlock: false,
      horizontalRule: false,
      heading: { levels: [2, 3] },
      link: {
        openOnClick: false,
        // Mirrors what the server stamps on every surviving link.
        HTMLAttributes: { rel: 'noopener noreferrer nofollow', target: '_blank' },
      },
    }),
  ],
  editorProps: {
    attributes: {
      class: 'rte-content focus:outline-none',
    },
  },
  onUpdate: ({ editor: instance }) => {
    emit('update:modelValue', instance.isEmpty ? '' : instance.getHTML())
  },
})

/*
 * Only re-seed when the incoming value is genuinely different from what the
 * editor already holds. Without the comparison, every keystroke's emit comes
 * back through the prop and resets the document — which moves the caret to
 * the start of the field on every character typed.
 */
watch(() => props.modelValue, (value) => {
  const instance = editor.value
  if (!instance) return

  const incoming = asHtml(value)
  const current = instance.isEmpty ? '' : instance.getHTML()

  if (incoming !== current) instance.commands.setContent(incoming, { emitUpdate: false })
})

watch(() => props.disabled, (isDisabled) => editor.value?.setEditable(!isDisabled))

onBeforeUnmount(() => editor.value?.destroy())

// ── The toolbar ──────────────────────────────────────────────────────

const linkOpen = ref(false)
const linkUrl = ref('')

function openLink(): void {
  linkUrl.value = editor.value?.getAttributes('link').href ?? ''
  linkOpen.value = true
}

function applyLink(): void {
  const url = linkUrl.value.trim()
  const chain = editor.value?.chain().focus().extendMarkRange('link')

  if (!url) {
    chain?.unsetLink().run()
  } else {
    // A bare "genesenn.com" typed by an admin is a RELATIVE url to a browser,
    // which would send the reader to admin.partner.syncvision.io/genesenn.com.
    // The server refuses anything that is not http/https/mailto, so guessing
    // https here is the difference between a working link and a silently
    // stripped one.
    const href = /^(https?:\/\/|mailto:)/i.test(url) ? url : `https://${url}`
    chain?.setLink({ href }).run()
  }

  linkOpen.value = false
}

/** One place for the button list, so the markup below stays readable. */
const marks = [
  { key: 'bold', label: 'ตัวหนา', icon: 'bold', run: () => editor.value?.chain().focus().toggleBold().run() },
  { key: 'italic', label: 'ตัวเอียง', icon: 'italic', run: () => editor.value?.chain().focus().toggleItalic().run() },
  { key: 'underline', label: 'ขีดเส้นใต้', icon: 'underline', run: () => editor.value?.chain().focus().toggleUnderline().run() },
  { key: 'strike', label: 'ขีดฆ่า', icon: 'strikethrough', run: () => editor.value?.chain().focus().toggleStrike().run() },
] as const

const blocks = [
  { key: 'bulletList', label: 'หัวข้อย่อย', icon: 'list_bullet', run: () => editor.value?.chain().focus().toggleBulletList().run(), active: () => editor.value?.isActive('bulletList') },
  { key: 'orderedList', label: 'ลำดับเลข', icon: 'list_numbered', run: () => editor.value?.chain().focus().toggleOrderedList().run(), active: () => editor.value?.isActive('orderedList') },
] as const
</script>

<template>
  <div class="rounded-lg border border-slate-200 bg-white overflow-hidden" :class="disabled ? 'opacity-60' : ''">
    <!-- Toolbar. Active state comes from the editor's own answer rather than
         local flags: the caret can land inside bold text without any button
         having been pressed, and a toolbar that disagrees with the cursor is
         worse than no toolbar. -->
    <div class="flex flex-wrap items-center gap-0.5 px-2 py-1.5 border-b border-slate-200 bg-slate-50">
      <button
        v-for="m in marks"
        :key="m.key"
        type="button"
        :data-test="`rte-${m.key}`"
        :title="m.label"
        :disabled="disabled"
        class="w-7 h-7 rounded flex items-center justify-center text-slate-600 hover:bg-slate-200 disabled:opacity-40"
        :class="editor?.isActive(m.key) ? 'bg-brand-100 text-brand-700' : ''"
        @click="m.run()"
      >
        <Icon :name="m.icon" :size="14" />
      </button>

      <span class="w-px h-5 bg-slate-200 mx-1"></span>

      <button
        v-for="level in [2, 3]"
        :key="`h${level}`"
        type="button"
        :data-test="`rte-h${level}`"
        :title="`หัวข้อ ${level === 2 ? 'ใหญ่' : 'เล็ก'}`"
        :disabled="disabled"
        class="h-7 px-2 rounded text-xs font-bold text-slate-600 hover:bg-slate-200 disabled:opacity-40"
        :class="editor?.isActive('heading', { level }) ? 'bg-brand-100 text-brand-700' : ''"
        @click="editor?.chain().focus().toggleHeading({ level: level as 2 | 3 }).run()"
      >
        H{{ level }}
      </button>

      <span class="w-px h-5 bg-slate-200 mx-1"></span>

      <button
        v-for="b in blocks"
        :key="b.key"
        type="button"
        :data-test="`rte-${b.key}`"
        :title="b.label"
        :disabled="disabled"
        class="w-7 h-7 rounded flex items-center justify-center text-slate-600 hover:bg-slate-200 disabled:opacity-40"
        :class="b.active() ? 'bg-brand-100 text-brand-700' : ''"
        @click="b.run()"
      >
        <Icon :name="b.icon" :size="14" />
      </button>

      <span class="w-px h-5 bg-slate-200 mx-1"></span>

      <button
        type="button"
        data-test="rte-link"
        title="ลิงก์"
        :disabled="disabled"
        class="w-7 h-7 rounded flex items-center justify-center text-slate-600 hover:bg-slate-200 disabled:opacity-40"
        :class="editor?.isActive('link') ? 'bg-brand-100 text-brand-700' : ''"
        @click="openLink()"
      >
        <Icon name="link" :size="14" />
      </button>
    </div>

    <!-- The link bar is inline rather than a modal: a dialog would take the
         selection's focus with it, and the selection is what the link
         attaches to. -->
    <div v-if="linkOpen" class="flex items-center gap-2 px-2 py-2 border-b border-slate-200 bg-brand-50">
      <input
        v-model="linkUrl"
        type="text"
        data-test="rte-link-url"
        placeholder="https://… (เว้นว่างเพื่อลบลิงก์)"
        class="flex-1 px-2 py-1 rounded border border-slate-200 text-xs"
        @keydown.enter.prevent="applyLink()"
      />
      <button type="button" class="px-2.5 py-1 rounded bg-brand-600 text-white text-xs font-bold" @click="applyLink()">ตกลง</button>
      <button type="button" class="px-2 py-1 text-xs font-bold text-slate-500" @click="linkOpen = false">ยกเลิก</button>
    </div>

    <EditorContent
      :editor="editor"
      class="px-3 py-2 text-sm"
      :style="{ minHeight: `${minHeight}px` }"
      data-test="rte-body"
    />

    <p v-if="placeholder && editor?.isEmpty" class="px-3 pb-2 -mt-1 text-xs text-slate-400 pointer-events-none">
      {{ placeholder }}
    </p>
  </div>
</template>

<style>
/*
 * NOT scoped: the content is rendered by ProseMirror inside EditorContent, so
 * a scoped rule's data attribute never reaches those elements. Namespaced
 * under .rte-content instead, which is the class set in editorProps above.
 *
 * Tailwind's preflight flattens headings and removes list markers, so an
 * editor styled by preflight alone shows a bulleted list with no bullets and
 * a heading the same size as body text — the admin formats, sees nothing
 * change, and concludes the buttons are broken.
 */
.rte-content { min-height: inherit; }
.rte-content:focus { outline: none; }
.rte-content > * + * { margin-top: 0.5rem; }
.rte-content h2 { font-size: 1.05rem; font-weight: 700; color: #0f172a; }
.rte-content h3 { font-size: 0.95rem; font-weight: 700; color: #1e293b; }
.rte-content ul { list-style: disc; padding-left: 1.25rem; }
.rte-content ol { list-style: decimal; padding-left: 1.5rem; }
.rte-content li > p { margin: 0; }
.rte-content a { color: #2563eb; text-decoration: underline; }
.rte-content strong { font-weight: 700; }
</style>
