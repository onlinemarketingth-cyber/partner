<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-15 — the messages the FRAMEWORK writes, in Thai.
 *
 * Every message this application writes itself was already Thai. Laravel's
 * own were not, and they are the ones a user meets at the worst moment: a
 * field left blank, a file too large, a date the wrong way round. A form that
 * speaks Thai until you make a mistake and then answers in English is a form
 * that abandons the reader exactly when they need it.
 *
 * ── THE PLACEHOLDER TEST IS THE ONE THAT EARNS ITS KEEP ──
 *
 * Laravel substitutes :attribute, :min, :other and friends BY NAME. A Thai
 * sentence may reorder them freely — and must spell them identically. A typo
 * is completely silent: the sentence renders with a literal ":attribuet" in
 * it, nothing throws, and it is only ever seen by whoever made the mistake
 * that triggered the message. test_every_translated_message_keeps_its_
 * placeholders() compares each Thai string against the English one it
 * replaces, so a dropped or misspelled placeholder fails here instead.
 */
class ThaiValidationMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_application_speaks_thai_by_default(): void
    {
        /*
         * Hardcoded in config/app.php, NOT read from .env — and this
         * assertion is the guard on that. Every .env in existence carries the
         * scaffolded `APP_LOCALE=en`, so restoring the env() read here would
         * silently return the whole application to English with nothing
         * failing. If somebody puts it back, this line fails.
         */
        $this->assertSame('th', config('app.locale'));
        // Still English, deliberately — a key missing from lang/th must fall
        // back to a readable sentence, never to the raw "validation.required".
        $this->assertSame('en', config('app.fallback_locale'));
    }

    public function test_a_refused_form_answers_in_thai(): void
    {
        /*
         * Through a real endpoint rather than the translator directly: the
         * locale has to be right at the moment the exception is rendered,
         * which is a different thing from the file being present.
         *
         * The login route is used because it needs no authentication and no
         * fixtures — this test is about the LANGUAGE of the refusal, not
         * about logging in.
         */
        $response = $this->postJson('/api/v1/login', [])->assertStatus(422);

        $message = $response->json('errors.email.0');

        $this->assertNotNull($message, 'the endpoint must refuse an empty body, or this asserts nothing');
        $this->assertStringNotContainsString('field is required', $message);
        $this->assertStringContainsString('กรุณากรอก', $message);
    }

    public function test_the_field_is_named_the_way_the_screen_names_it(): void
    {
        // Without the `attributes` list the subject of the sentence is the
        // column name — "กรุณากรอก email" — under a box labelled "อีเมล".
        $this->postJson('/api/v1/login', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'กรุณากรอก อีเมล');
    }

    public function test_every_translated_message_keeps_its_placeholders(): void
    {
        $english = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        $thai = require lang_path('th/validation.php');

        $checked = 0;

        foreach ($this->flatten($thai) as $key => $translated) {
            // `custom` and `attributes` are this application's own additions,
            // not translations of an English sentence — nothing to compare.
            if (str_starts_with($key, 'custom.') || str_starts_with($key, 'attributes.')) {
                continue;
            }

            $source = data_get($english, $key);

            $this->assertIsString($source, "lang/th/validation.php has a key Laravel does not: {$key}");

            $this->assertSame(
                $this->placeholders($source),
                $this->placeholders($translated),
                "the Thai wording of '{$key}' does not carry the same placeholders as Laravel's",
            );

            $checked++;
        }

        // Guards the guard: a flatten() that silently returned nothing would
        // make every assertion above vacuous and this test permanently green.
        $this->assertGreaterThan(100, $checked);
    }

    public function test_no_message_laravel_defines_was_left_untranslated(): void
    {
        /*
         * The fallback means a missing key degrades to English rather than
         * breaking — which is the right behaviour and also the reason a gap
         * can sit here unnoticed for a year. This lists them instead.
         *
         * `custom.attribute-name` is Laravel's own commented-out example key,
         * not a message.
         */
        $english = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        $thai = $this->flatten(require lang_path('th/validation.php'));

        $missing = array_values(array_filter(
            array_keys($this->flatten($english)),
            fn (string $key) => ! str_starts_with($key, 'custom.') && ! array_key_exists($key, $thai),
        ));

        $this->assertSame([], $missing, 'these validation messages still answer in English: '.implode(', ', $missing));
    }

    /**
     * @param  array<string, mixed>  $messages
     * @return array<string, string>
     */
    private function flatten(array $messages, string $prefix = ''): array
    {
        $flat = [];

        foreach ($messages as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $flat += $this->flatten($value, $path);

                continue;
            }

            $flat[$path] = (string) $value;
        }

        return $flat;
    }

    /** @return list<string> every :placeholder in a message, sorted so order may differ */
    private function placeholders(string $message): array
    {
        preg_match_all('/:[a-z_]+/', $message, $matches);

        $found = array_unique($matches[0]);
        sort($found);

        return array_values($found);
    }
}
