<?php

namespace Tests\Unit;

use App\Support\RichText;
use PHPUnit\Framework\TestCase;

/**
 * 2026-09-09 — the adversarial half of RichText.
 *
 * Until today no field in this system could become markup: everything went out
 * through Vue's `{{ }}`, which escapes. A formatting toolbar ends that, and
 * `products.description` is rendered on `/p/{token}` — a PUBLIC page an agent
 * sends to a prospect. So the question this file answers is not "does bold
 * survive", it is "what does NOT survive", and the cases below are written to
 * fail if the sanitiser is ever loosened or replaced.
 *
 * Every assertion is on the SANITISED STRING, not on a rendered page: this
 * runs on write, and what it returns is what the database will hold forever.
 */
class RichTextSanitiserTest extends TestCase
{
    // ── What must survive ────────────────────────────────────────────

    public function test_the_toolbar_marks_all_survive(): void
    {
        // Exactly what the editor can produce and nothing more (human:
        // "ชุดปุ่มพอไหม — พอ").
        $html = '<h2>หัวข้อ</h2><p><strong>หนา</strong> <em>เอียง</em> <u>ขีดเส้นใต้</u> <s>ขีดฆ่า</s></p>'
            .'<ul><li>ข้อหนึ่ง</li></ul><ol><li>ลำดับหนึ่ง</li></ol><p>บรรทัด<br>ใหม่</p>';

        $clean = RichText::sanitize($html);

        foreach (['<h2>', '<strong>', '<em>', '<u>', '<s>', '<ul>', '<ol>', '<li>', '<br>'] as $tag) {
            $this->assertStringContainsString($tag, (string) $clean, "หายไป: {$tag}");
        }
    }

    public function test_thai_text_comes_back_as_thai(): void
    {
        /*
         * libxml has no HTML5 parser and assumes ISO-8859-1 for a fragment
         * with no declared encoding. Without the encoding step in sanitize(),
         * every Thai character becomes mojibake — a corruption that would be
         * written to the database and would look like a font problem.
         */
        $clean = RichText::sanitize('<p>โปรแกรมตรวจสุขภาพเชิงป้องกัน 1 ปี</p>');

        $this->assertStringContainsString('โปรแกรมตรวจสุขภาพเชิงป้องกัน 1 ปี', (string) $clean);
    }

    public function test_plain_text_typed_before_today_is_left_alone(): void
    {
        // Every existing row is plain text. The migration story for this
        // feature is that there is no migration, and this is that promise.
        $this->assertSame('GENESENN 1-Year Vital Blueprint', RichText::sanitize('GENESENN 1-Year Vital Blueprint'));
    }

    // ── What must not ────────────────────────────────────────────────

    public function test_a_script_tag_is_removed_with_its_contents(): void
    {
        // Removed, not unwrapped: unwrapping would spill the source into the
        // page as visible text.
        $clean = (string) RichText::sanitize('<p>ก่อน</p><script>alert(document.cookie)</script><p>หลัง</p>');

        $this->assertStringNotContainsString('script', $clean);
        $this->assertStringNotContainsString('alert', $clean);
        $this->assertStringContainsString('ก่อน', $clean);
        $this->assertStringContainsString('หลัง', $clean);
    }

    /**
     * @dataProvider eventHandlerPayloads
     */
    public function test_no_event_handler_survives_on_any_element(string $payload): void
    {
        /*
         * These are not defeated one by one — every attribute is dropped
         * without being inspected, and only href is put back. That is what
         * makes the whole ~100-name family go, including the ones nobody
         * remembers.
         */
        $clean = (string) RichText::sanitize($payload);

        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringNotContainsString('onload', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('onfocus', $clean);
        $this->assertStringNotContainsString('alert', $clean);
    }

    /** @return array<string, array{string}> */
    public static function eventHandlerPayloads(): array
    {
        return [
            'img onerror' => ['<img src=x onerror=alert(1)>'],
            'body onload' => ['<body onload=alert(1)>ข้อความ</body>'],
            'allowed tag, forbidden attribute' => ['<p onclick="alert(1)">ข้อความ</p>'],
            'autofocus' => ['<input autofocus onfocus=alert(1)>'],
            'svg' => ['<svg/onload=alert(1)>'],
            'details ontoggle' => ['<details open ontoggle=alert(1)>x</details>'],
        ];
    }

    /**
     * @dataProvider dangerousHrefs
     */
    public function test_a_dangerous_link_loses_its_destination_but_keeps_its_words(string $href): void
    {
        /*
         * `javascript:` is the one everybody guards; `data:` is the one that
         * gets forgotten, and a data: URL can carry a whole HTML document.
         * The whitespace and control-character variants are exactly how a
         * str_starts_with('javascript:') check gets walked past — browsers
         * ignore those characters, so a checker must too.
         */
        $clean = (string) RichText::sanitize('<p><a href="'.$href.'">กดที่นี่</a></p>');

        $this->assertStringNotContainsString('href', $clean, "ยังมี href: {$href}");
        $this->assertStringContainsString('กดที่นี่', $clean, 'ข้อความของลิงก์ต้องไม่หาย');
    }

    /** @return array<string, array{string}> */
    public static function dangerousHrefs(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'javascript uppercase' => ['JaVaScRiPt:alert(1)'],
            'javascript with a tab' => ["java\tscript:alert(1)"],
            'javascript with a newline' => ["java\nscript:alert(1)"],
            'javascript with leading space' => ['  javascript:alert(1)'],
            'data html' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'vbscript' => ['vbscript:msgbox(1)'],
            'file' => ['file:///etc/passwd'],
        ];
    }

    public function test_a_real_link_survives_and_is_hardened(): void
    {
        $clean = (string) RichText::sanitize('<p><a href="https://genesenn.com/vital">รายละเอียด</a></p>');

        $this->assertStringContainsString('href="https://genesenn.com/vital"', $clean);
        // A link in customer-facing content opens away from the app, and
        // noopener stops the opened page reaching back through window.opener.
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $clean);
        $this->assertStringContainsString('target="_blank"', $clean);
    }

    public function test_a_mailto_link_survives(): void
    {
        $clean = (string) RichText::sanitize('<p><a href="mailto:care@genesenn.com">อีเมล</a></p>');

        $this->assertStringContainsString('mailto:care@genesenn.com', $clean);
    }

    public function test_styles_classes_and_ids_are_all_dropped(): void
    {
        // Content must not be able to restyle the page it lands on — a
        // position:fixed overlay in a product description is a defacement of
        // the storefront even without any script.
        $clean = (string) RichText::sanitize('<p class="x" id="y" style="position:fixed;inset:0" data-z="1">ข้อความ</p>');

        $this->assertSame('<p>ข้อความ</p>', $clean);
    }

    public function test_a_style_block_is_removed_entirely(): void
    {
        $clean = (string) RichText::sanitize('<style>body{display:none}</style><p>ข้อความ</p>');

        $this->assertStringNotContainsString('display:none', $clean);
        $this->assertStringContainsString('ข้อความ', $clean);
    }

    public function test_an_iframe_is_removed(): void
    {
        $clean = (string) RichText::sanitize('<p>ก่อน</p><iframe src="https://evil.example"></iframe>');

        $this->assertStringNotContainsString('iframe', $clean);
        $this->assertStringContainsString('ก่อน', $clean);
    }

    public function test_a_pasted_word_document_keeps_its_words(): void
    {
        /*
         * The everyday case, not an attack: a paste from Word or Google Docs
         * arrives wrapped in <div><span style=…>. Deleting unknown elements
         * instead of UNWRAPPING them would silently eat the text somebody had
         * just pasted — a data-loss bug that would be blamed on the editor.
         */
        $clean = (string) RichText::sanitize(
            '<div class="WordSection1"><span style="font-family:Angsana">ตรวจสุขภาพประจำปี</span></div>'
        );

        $this->assertStringContainsString('ตรวจสุขภาพประจำปี', $clean);
        $this->assertStringNotContainsString('Angsana', $clean);
        $this->assertStringNotContainsString('<div', $clean);
    }

    public function test_malformed_markup_does_not_survive_as_markup(): void
    {
        /*
         * Unbalanced and half-written tags are the shape an attack usually
         * takes. Parsing beats pattern-matching here: a tag libxml cannot make
         * sense of does not come out the other side as a TAG.
         *
         * What is left over is inert text — "ipt&gt;alert(1)" — and that is the
         * correct outcome, not a miss. Asserting the string "alert(1)" is
         * absent would be asserting the wrong thing: escaped text that happens
         * to spell a function call does nothing, and a sanitiser that deleted
         * it would also delete a paragraph about JavaScript.
         */
        $clean = (string) RichText::sanitize('<p>ข้อความ<scr<script>ipt>alert(1)</scr</script>ipt></p>');

        $this->assertStringNotContainsString('<script', $clean);
        // The only tags left are the ones we put there.
        $this->assertSame([], array_values(array_diff(
            self::tagsIn($clean),
            ['p', 'br', 'strong', 'em', 'u', 's', 'ul', 'ol', 'li', 'h2', 'h3', 'a'],
        )));
        // And the leftovers are escaped, so the browser reads them as words.
        $this->assertStringContainsString('&gt;', $clean);
    }

    /**
     * Every element name that actually survived, so a test can assert on the
     * SET of tags rather than on one string at a time.
     *
     * @return list<string>
     */
    private static function tagsIn(string $html): array
    {
        preg_match_all('/<\/?([a-z0-9]+)/i', $html, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }

    public function test_an_html_comment_is_removed(): void
    {
        // A conditional comment is markup a browser may still act on.
        $clean = (string) RichText::sanitize('<p>ข้อความ</p><!--[if IE]><script>alert(1)</script><![endif]-->');

        $this->assertStringNotContainsString('alert', $clean);
        $this->assertStringNotContainsString('<!--', $clean);
    }

    // ── Empty is empty ───────────────────────────────────────────────

    public function test_an_emptied_editor_stores_null_not_an_empty_paragraph(): void
    {
        /*
         * Tiptap emits "<p></p>" for an empty document. Stored as-is it would
         * be truthy forever: every `v-if="product.description"` in both apps
         * would keep rendering a section containing one blank line, and no
         * amount of deleting in the editor would clear it.
         */
        $this->assertNull(RichText::sanitize('<p></p>'));
        $this->assertNull(RichText::sanitize('<p><br></p>'));
        $this->assertNull(RichText::sanitize('   '));
        $this->assertNull(RichText::sanitize(''));
        $this->assertNull(RichText::sanitize(null));
    }

    public function test_a_payload_that_is_entirely_stripped_becomes_null(): void
    {
        // Not an empty string that later reads as "the admin wrote something".
        $this->assertNull(RichText::sanitize('<script>alert(1)</script>'));
    }

    // ── Plain text extraction ────────────────────────────────────────

    public function test_plain_text_puts_a_space_where_a_block_ended(): void
    {
        /*
         * The 140-character announcement preview reads this. Without the
         * block-boundary spaces "<p>ก่อน</p><p>หลัง</p>" would come out as
         * "ก่อนหลัง" — two sentences welded into one word.
         */
        $this->assertSame('ก่อน หลัง', RichText::toPlainText('<p>ก่อน</p><p>หลัง</p>'));
        $this->assertSame('หนึ่ง สอง', RichText::toPlainText('<ul><li>หนึ่ง</li><li>สอง</li></ul>'));
        $this->assertSame('บรรทัด ใหม่', RichText::toPlainText('บรรทัด<br>ใหม่'));
    }

    public function test_plain_text_decodes_entities(): void
    {
        // A preview showing "&amp;" is a bug the reader blames on the writer.
        $this->assertSame('A & B', RichText::toPlainText('<p>A &amp; B</p>'));
    }

    public function test_plain_text_of_nothing_is_an_empty_string(): void
    {
        $this->assertSame('', RichText::toPlainText(null));
        $this->assertSame('', RichText::toPlainText(''));
    }
}
