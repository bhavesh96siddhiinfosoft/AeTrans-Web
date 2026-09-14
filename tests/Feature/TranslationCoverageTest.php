<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Every string the site shows exists in every language it offers.
 *
 * This test is here because the check found sixty strings that had NEVER been
 * translated — the shuttle flow, the map picker and the booking error messages, all
 * added after the first translation pass, and none of them noticed. Laravel returns the
 * KEY when a line is missing, so a missing translation is not an error: the customer
 * simply reads `lang.pickup_address` where a sentence should be. With the English
 * sentence as the key it was worse — the page looked correct to anyone who did not speak
 * the language.
 *
 * The Indonesian and Arabic files are DRAFTS awaiting a native reader. This does not
 * check that the words are good — only that there ARE words, which is the failure that
 * hides.
 */
class TranslationCoverageTest extends TestCase
{
    /** Every language the site can serve. English is the source. */
    private const LANGUAGES = ['en', 'id', 'ar'];

    private const SOURCE = 'en';

    public function test_every_language_carries_exactly_the_same_keys(): void
    {
        $source = array_keys($this->lines(self::SOURCE));

        $this->assertGreaterThan(200, count($source), 'The English file is suspiciously small.');

        foreach (self::LANGUAGES as $language) {
            if ($language === self::SOURCE) {
                continue;
            }

            $keys = array_keys($this->lines($language));

            $this->assertSame([], array_values(array_diff($source, $keys)), sprintf(
                'lang/%s/lang.php is missing keys the English file has.',
                $language,
            ));

            $this->assertSame([], array_values(array_diff($keys, $source)), sprintf(
                'lang/%s/lang.php has keys the English file does not — a rename left an orphan.',
                $language,
            ));
        }
    }

    /** Every key the code asks for exists. A missing one prints as `lang.whatever`. */
    public function test_every_key_the_code_uses_exists(): void
    {
        $lines = $this->lines(self::SOURCE);
        $used = $this->keysUsed();

        $this->assertGreaterThan(200, count($used), 'The scan found suspiciously few keys.');

        $missing = array_values(array_diff($used, array_keys($lines)));

        $this->assertSame([], $missing, "lang/en/lang.php is missing:\n  ".implode("\n  ", $missing));
    }

    /** And nothing is carried that nothing asks for. */
    public function test_no_line_is_left_behind_after_a_screen_changes(): void
    {
        $unused = array_values(array_diff(array_keys($this->lines(self::SOURCE)), $this->keysUsed()));

        /*
         * Keys BUILT FROM DATA, which a text scan cannot see.
         *
         * `status_*` come from a booking's own status (booking/history.blade.php), and
         * `email_status_*` are the four status emails, whose subject, heading, lead and
         * preview keys are each assembled from the status in `BookingStatusMail`.
         *
         * The exemption is a prefix rather than a list because the panel can add a
         * status; what it costs is that a genuinely dead `email_status_` line would not
         * be noticed here. `BookingStatusMail::TONES` is the real list, and a status
         * missing from it falls back rather than failing.
         */
        $dynamic = ['status_', 'email_status_'];

        $unused = array_values(array_filter(
            $unused,
            fn (string $key) => ! \Illuminate\Support\Str::startsWith($key, $dynamic),
        ));

        $this->assertSame([], $unused, "Nothing uses:\n  ".implode("\n  ", $unused));
    }

    /**
     * A placeholder must survive translation.
     *
     * Laravel substitutes on the literal token, so `:count` translated into another word
     * silently stops being replaced and the customer reads ":count" — or, worse, reads a
     * sentence with the number missing and no sign anything went wrong.
     */
    public function test_placeholders_survive_every_translation(): void
    {
        $source = $this->lines(self::SOURCE);

        foreach (self::LANGUAGES as $language) {
            foreach ($this->lines($language) as $key => $line) {
                $this->assertSame(
                    $this->placeholders($source[$key] ?? ''),
                    $this->placeholders($line),
                    sprintf('lang/%s/lang.php changed the placeholders of "%s".', $language, $key),
                );
            }
        }
    }

    /** Laravel's own authentication lines, which the framework ships only in English. */
    public function test_every_language_translates_the_framework_authentication_lines(): void
    {
        foreach (self::LANGUAGES as $language) {
            $path = base_path("lang/{$language}/auth.php");

            $this->assertFileExists($path, "lang/{$language}/auth.php is missing.");

            $lines = require $path;

            foreach (['failed', 'password', 'throttle'] as $key) {
                $this->assertArrayHasKey($key, $lines, "auth.{$key} is missing in {$language}.");
                $this->assertNotSame('', trim((string) $lines[$key]));
            }

            // `:seconds` is what the throttle message counts down with.
            $this->assertStringContainsString(':seconds', (string) $lines['throttle']);
        }
    }

    /** @return array<string, string> */
    private function lines(string $language): array
    {
        $path = base_path("lang/{$language}/lang.php");

        $this->assertFileExists($path, "lang/{$language}/lang.php is missing.");

        return require $path;
    }

    /** @return array<int, string> */
    private function placeholders(string $line): array
    {
        preg_match_all('/:[a-zA-Z]+/', $line, $found);
        sort($found[0]);

        return $found[0];
    }

    /**
     * Every `lang.*` key handed to `__()` anywhere the customer can reach.
     *
     * @return array<int, string>
     */
    private function keysUsed(): array
    {
        $keys = [];

        foreach ([resource_path('views'), app_path()] as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($files as $file) {
                if ($file->isDir() || ! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                preg_match_all(
                    "/__\(\s*'lang\.([a-z0-9_]+)'/",
                    (string) file_get_contents($file->getPathname()),
                    $matches,
                );

                foreach ($matches[1] as $key) {
                    // A key ending in `_` is the left half of a concatenation —
                    // `__('lang.status_'.$status)` — not a line anybody wrote.
                    if (! str_ends_with($key, '_')) {
                        $keys[$key] = true;
                    }
                }
            }
        }

        return array_keys($keys);
    }
}
