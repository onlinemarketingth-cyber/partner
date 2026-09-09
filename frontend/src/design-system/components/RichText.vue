<script setup lang="ts">
/**
 * RichText — 2026-09-09. The read side of RichTextEditor.
 *
 * ── WHY A COMPONENT AND NOT `v-html` AT EACH SITE ──
 *
 * `v-html` tells the browser to interpret a string as markup, which is the
 * one thing Vue's `{{ }}` exists to prevent. Scattering it across a dozen
 * templates makes "which strings do we trust, and why" a question that has to
 * be re-answered at every site, by whoever is editing that file next. Here it
 * is answered once, in writing.
 *
 * ── WHAT MAKES THIS SAFE ──
 *
 * NOT this component. The gate is `App\Support\RichText` on the SERVER, which
 * cleans these fields on WRITE against a nine-element allowlist, so the
 * database cannot hold markup the application did not choose to allow. That
 * placement is deliberate: a sanitiser that ran here would be bypassed by the
 * next report, export or template that read the same column.
 *
 * ── SO THIS COMPONENT MAY ONLY BE GIVEN THOSE FIELDS ──
 *
 *   products.description · products.spec_description
 *   product_catalog_items.description · product_catalog_items.spec_description
 *   announcements.content
 *
 * Anything else — a client's health notes, a name, an address, a value typed
 * into a spec row, anything from a third party — has never been through that
 * gate and must keep going out through `{{ }}`. If a new field needs
 * formatting, it goes through the Form Request trait FIRST; adding it here
 * first is how the gate gets bypassed.
 */
withDefaults(defineProps<{
  html: string | null | undefined
  /** Rendered small, for a card or a side panel. */
  compact?: boolean
}>(), { compact: false })
</script>

<template>
  <!-- eslint-disable vue/no-v-html -- server-sanitised on write; see docblock -->
  <div v-if="html" class="rich-text" :class="compact ? 'rich-text--compact' : ''" v-html="html"></div>
</template>

<style>
/*
 * NOT scoped, and namespaced under .rich-text instead.
 *
 * The markup here arrives as a string, so it carries none of the scoped-style
 * data attributes Vue stamps on template-authored elements — a `scoped` block
 * would simply never match any of it. (This is also why the same rules cannot
 * just be Tailwind classes: there is no template to put them on.)
 *
 * Tailwind's preflight flattens headings and strips list markers, so without
 * these an admin who bullets a list watches the bullets vanish the moment
 * they save, and reasonably concludes the editor is broken.
 *
 * Kept in step with RichTextEditor.vue's own .rte-content block: the editor
 * and the page it publishes to should not disagree about what a heading looks
 * like.
 */
.rich-text { color: inherit; }
.rich-text > * + * { margin-top: 0.5rem; }
.rich-text h2 { font-size: 1.05rem; font-weight: 700; }
.rich-text h3 { font-size: 0.95rem; font-weight: 700; }
.rich-text ul { list-style: disc; padding-left: 1.25rem; }
.rich-text ol { list-style: decimal; padding-left: 1.5rem; }
.rich-text li { margin-top: 0.125rem; }
.rich-text li > p { margin: 0; }
.rich-text a { color: #2563eb; text-decoration: underline; }
.rich-text a:hover { text-decoration: none; }
.rich-text strong { font-weight: 700; }
.rich-text em { font-style: italic; }
.rich-text u { text-decoration: underline; }
.rich-text s { text-decoration: line-through; }

/* A long word or a pasted URL must wrap rather than widen its container and
   push the page into a horizontal scroll. */
.rich-text { overflow-wrap: anywhere; }

.rich-text--compact { font-size: 0.8125rem; }
.rich-text--compact h2 { font-size: 0.9rem; }
.rich-text--compact h3 { font-size: 0.85rem; }
</style>
