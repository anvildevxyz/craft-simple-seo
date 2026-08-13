<?php

namespace anvildev\simpleseo\tests\unit;

use anvildev\simpleseo\fields\SeoField;
use PHPUnit\Framework\TestCase;

/**
 * Every translatable string in the source must exist in every shipped locale
 * file, and no locale value may be empty. New t() strings fail this test
 * until translated — translations can never silently drift.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class TranslationsCoverageTest extends TestCase
{
    // Public Methods
    // =========================================================================

    /**
     * All shipped locales cover all source strings.
     */
    public function testAllLocalesCoverAllSourceStrings(): void
    {
        $src = dirname(__DIR__, 2) . '/src';
        $strings = $this->_collectSourceStrings($src);
        $this->assertNotEmpty($strings, 'source string extraction must find strings');
        $this->assertContains('Preview and robots', $strings);
        $this->assertContains('Preview', $strings);

        foreach (['de', 'fr', 'es', 'it'] as $locale) {
            $file = "$src/translations/$locale/simple-seo.php";
            $this->assertFileExists($file);

            /** @var array<string, string> $map */
            $map = require $file;

            $missing = array_values(array_diff($strings, array_keys($map)));
            $this->assertSame([], $missing, "Locale '$locale' is missing translations");

            foreach ($map as $key => $value) {
                $this->assertNotSame('', trim($value), "Locale '$locale' has an empty translation for '$key'");
            }
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Extracts every `Craft::t('simple-seo', …)` and `|t('simple-seo')`
     * source string from PHP and Twig files.
     *
     * @return string[]
     */
    private function _collectSourceStrings(string $src): array
    {
        $strings = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src));

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            $ext = $file->getExtension();
            if (!in_array($ext, ['php', 'twig'], true)) {
                continue;
            }
            $text = (string)file_get_contents($file->getPathname());

            if ($ext === 'php') {
                preg_match_all("/Craft::t\(\s*'simple-seo',\s*'((?:[^'\\\\]|\\\\.)*)'/", $text, $matches);
            } else {
                // Each translatable token must sit next to |t() — wrapping a
                // ternary and translating the result hides the unused branch
                // from this extractor. The trailing `[,)]` is load-bearing:
                // without it, strings translated with params —
                // |t('simple-seo', { token: … }) — slip past extraction.
                preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'\|t\('simple-seo'\s*[,)]/", $text, $matches);
            }
            foreach ($matches[1] as $match) {
                $strings[] = str_replace("\\'", "'", $match);
            }
        }

        // SUBFIELDS labels and SUBFIELD_GROUPS headings reach Craft::t()
        // through a variable, so the literal-only extraction above is blind
        // to them. Without this, a new sub-field or group ships untranslated
        // and this test still passes.
        $strings = [
            ...$strings,
            ...array_values(SeoField::SUBFIELDS),
            ...array_keys(SeoField::SUBFIELD_GROUPS),
        ];

        return array_values(array_unique($strings));
    }
}
