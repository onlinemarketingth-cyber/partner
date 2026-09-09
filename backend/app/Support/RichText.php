<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * 2026-09-09 (human: "ตัวไหนที่เป็นคำอธิบาย หรือ ต้องการกรอกข้อความยาวๆ
 * ให้เป็น text editor ในการจัดการแทนทั้งหมด").
 *
 * The gate between a rich-text editor and the database.
 *
 * ── WHY THIS EXISTS AT ALL ──
 *
 * Until today every long-form field in this system was plain text, rendered
 * through Vue's `{{ }}` interpolation, which escapes everything. There was no
 * code path by which anything a person typed could become markup, so no
 * sanitiser was needed and none existed.
 *
 * A formatting toolbar ends that. Bold and bullet points mean the stored value
 * is markup, and rendering it means telling the browser to interpret it —
 * `v-html`. `products.description` is rendered on `/p/{token}`, a PUBLIC page
 * an agent sends to a prospect, so "interpret whatever is in this column" is a
 * stored-XSS hole aimed at people who are not even users of the system.
 *
 * ── WHERE THE GATE IS ──
 *
 * ON WRITE, in the Form Request, not on render. Two reasons, and the second is
 * the one that matters:
 *
 *   1. It runs once per save instead of once per view.
 *   2. The DATABASE never holds anything dangerous. A sanitiser that only runs
 *      at render time is one forgotten `v-html`, one new report, one CSV
 *      export away from being bypassed — and by then the payload is already
 *      stored and the bypass is somebody else's code.
 *
 * ── WHY HAND-WRITTEN, AND WHY THAT IS NOT THE USUAL MISTAKE ──
 *
 * The usual mistake is a REGEX sanitiser with a large allowlist. This is
 * neither: it parses with libxml (the same parser a browser-shaped tokeniser
 * would use, not a pattern match over a string) and the allowlist is nine
 * elements and exactly one attribute. Everything not on the list is not
 * "escaped" or "filtered" — it is structurally removed from a parsed tree.
 *
 * The alternative was ezyang/HTMLPurifier via mews/purifier, which is the
 * better-known answer and would be the right one for a large allowlist. It is
 * not used here because packagist is unreachable from the environment this was
 * written in, and shipping an UNTESTED sanitiser — however well-regarded the
 * library — in front of a public page is worse than a small one with the
 * adversarial tests in RichTextSanitiserTest.
 *
 * ── THE ALLOWLIST IS THE TOOLBAR ──
 *
 * Exactly the marks the editor can produce (human, 2026-09-09: bold, italic,
 * underline, bullet list, numbered list, heading, link — "พอ"). Nothing else
 * has a way into the field through the UI, so nothing else needs to survive:
 * no images, no tables, no styles, no classes, no ids, no data-attributes.
 */
final class RichText
{
    /** Exactly what the editor's toolbar can produce. Nothing else. */
    private const ALLOWED_ELEMENTS = ['p', 'br', 'strong', 'em', 'u', 's', 'ul', 'ol', 'li', 'h2', 'h3', 'a'];

    /**
     * The only attribute that survives anywhere, and only on <a>.
     *
     * Every other attribute is dropped without inspection — which is what
     * removes the entire event-handler family (onclick, onerror, onload and
     * the ~100 others) in one rule rather than by enumerating them, and an
     * enumeration is what eventually misses one.
     */
    private const ALLOWED_LINK_SCHEMES = ['http', 'https', 'mailto'];

    /**
     * Clean one value on its way into the database.
     *
     * Returns null for anything that carries no text at all, so an editor
     * emptied back out stores NULL rather than "<p></p>" — which would render
     * as a stray blank line forever and defeat every `v-if="description"` in
     * both apps.
     */
    public static function sanitize(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $html = trim($html);

        if ($html === '') {
            return null;
        }

        $document = new DOMDocument('1.0', 'UTF-8');

        /*
         * Two things are going on in this one parse call, and both are about
         * getting a FRAGMENT through a parser built for documents.
         *
         * The <meta charset> is not decoration: libxml has no HTML5 parser and
         * assumes ISO-8859-1 for a fragment that declares nothing, which turns
         * every Thai character into mojibake — a corruption that would be
         * written to the database and would look like a font bug. (The older
         * trick, mb_convert_encoding(..., 'HTML-ENTITIES'), is deprecated as
         * of PHP 8.2 and also leaves Thai stored as &#3605; forever, which
         * breaks every preview and every search over the column.)
         *
         * The wrapper <div> gives the walk below a single, known root, so the
         * fragment's own top-level nodes are children rather than document
         * children — where libxml's rules about what may appear differ.
         *
         * Errors are suppressed and cleared rather than trusted: malformed
         * markup is EXPECTED here — it is the shape an attack usually takes —
         * and libxml's recovery is precisely why parsing beats pattern
         * matching. A tag it cannot make sense of does not survive as a tag.
         */
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<meta charset="utf-8"><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementsByTagName('div')->item(0);

        if (! $root instanceof DOMElement) {
            return null;
        }

        foreach (iterator_to_array($root->childNodes) as $node) {
            self::clean($node, $document);
        }

        $clean = '';
        foreach ($root->childNodes as $node) {
            $clean .= (string) $document->saveHTML($node);
        }
        $clean = trim($clean);

        // Nothing but markup left (an emptied editor, or a payload that was
        // entirely stripped) is not content.
        return trim(strip_tags($clean)) === '' ? null : $clean;
    }

    /** The plain text inside rich text — for previews, search and length caps. */
    public static function toPlainText(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        // Block-level tags become a space first, or "<p>a</p><p>b</p>" would
        // read as "ab" in a 140-character preview.
        $spaced = preg_replace('/<(br|\/p|\/li|\/h2|\/h3)\b[^>]*>/i', ' ', $html) ?? $html;

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    /**
     * Depth-first, and destructive: a node that is not allowed is either
     * removed entirely or UNWRAPPED (its text kept, its tag discarded).
     *
     * Unwrapping rather than deleting matters for the everyday case, which is
     * not an attack: a paste from Word or a Google Doc arrives wrapped in
     * <span style=…> and <div>, and deleting those would silently eat the
     * text somebody just pasted.
     *
     * <script> and <style> are the exception and are removed WITH their
     * contents — unwrapping a <script> would spill its source into the page as
     * visible text, and a stylesheet is not prose either.
     */
    private static function clean(DOMNode $node, DOMDocument $document): void
    {
        if ($node instanceof DOMText) {
            return;
        }

        // Comments, CDATA, processing instructions, doctypes: not content, and
        // a conditional comment is markup a browser may still act on.
        if (! $node instanceof DOMElement) {
            $node->parentNode?->removeChild($node);

            return;
        }

        $name = strtolower($node->nodeName);

        if (in_array($name, ['script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'svg', 'math'], true)) {
            $node->parentNode?->removeChild($node);

            return;
        }

        // Children first: unwrapping a parent below re-parents them, and a
        // node moved mid-walk is a node not walked.
        foreach (iterator_to_array($node->childNodes) as $child) {
            self::clean($child, $document);
        }

        if (! in_array($name, self::ALLOWED_ELEMENTS, true)) {
            self::unwrap($node);

            return;
        }

        self::stripAttributes($node);

        if ($name === 'a') {
            self::cleanLink($node);
        }
    }

    /** Replace an element with its own children, keeping the text. */
    private static function unwrap(DOMElement $node): void
    {
        $parent = $node->parentNode;

        if (! $parent) {
            return;
        }

        while ($node->firstChild) {
            $parent->insertBefore($node->firstChild, $node);
        }

        $parent->removeChild($node);
    }

    /**
     * Drop every attribute. `href` is put back by cleanLink() only after it
     * has been checked — an allowlist applied to what is left, rather than a
     * blocklist applied to what arrived.
     */
    private static function stripAttributes(DOMElement $node): void
    {
        $href = $node->nodeName === 'a' ? $node->getAttribute('href') : '';

        foreach (iterator_to_array($node->attributes ?? []) as $attribute) {
            $node->removeAttribute($attribute->nodeName);
        }

        if ($href !== '') {
            $node->setAttribute('href', $href);
        }
    }

    /**
     * A link keeps its href only if the scheme is one a person could have
     * meant. `javascript:` is the obvious one; `data:` is the one that gets
     * forgotten, and a data: URL can carry a whole HTML document.
     *
     * The value is normalised before it is read — leading control characters
     * and whitespace ("java\tscript:alert(1)") are exactly how a naive
     * str_starts_with check gets walked past, and browsers ignore them.
     */
    private static function cleanLink(DOMElement $node): void
    {
        $href = $node->getAttribute('href');
        $normalised = strtolower(preg_replace('/[\x00-\x20]+/', '', $href) ?? '');

        $safe = str_starts_with($normalised, '/')
            || str_starts_with($normalised, '#')
            || in_array(strtok($normalised, ':') ?: '', self::ALLOWED_LINK_SCHEMES, true) && str_contains($normalised, ':');

        if (! $safe) {
            // The text stays; only the destination goes. Removing the whole
            // element would delete words somebody wrote.
            self::unwrap($node);

            return;
        }

        $node->setAttribute('href', $href);
        // A link in agent- or customer-facing content opens away from the app,
        // and `noopener` keeps the opened page from reaching back through
        // window.opener.
        $node->setAttribute('target', '_blank');
        $node->setAttribute('rel', 'noopener noreferrer nofollow');
    }
}
