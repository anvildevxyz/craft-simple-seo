<?php

namespace anvildev\simpleseo\tests\unit\helpers;

use anvildev\simpleseo\helpers\TitleFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Title-format contract, driven by the shared vector file
 * tests/fixtures/title-format-cases.json — the same vectors drive the JS
 * mirror (seo-field.js formatSeoTitle) via tests/js/preview-format.test.js,
 * so the two implementations cannot drift apart case-by-case.
 *
 * @phpstan-type TitleFormatCase array{note: string, title: string|null, fallback: string, siteName: string, format: string|null, expected: string}
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class TitleFormatterTest extends TestCase
{
    // Public Methods
    // =========================================================================

    /**
     * Provides the shared PHP/JS test vectors, keyed by their notes.
     *
     * @return array<string, array{0: TitleFormatCase}>
     */
    public static function titleFormatCases(): array
    {
        $json = (string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/title-format-cases.json');
        /** @var array<int, TitleFormatCase> $cases */
        $cases = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $provided = [];
        foreach ($cases as $case) {
            $provided[$case['note']] = [$case];
        }

        return $provided;
    }

    /**
     * Every shared vector formats identically in PHP.
     *
     * @param TitleFormatCase $case
     */
    #[DataProvider('titleFormatCases')]
    public function testSharedVector(array $case): void
    {
        $this->assertSame(
            $case['expected'],
            TitleFormatter::format($case['title'], $case['fallback'], $case['siteName'], $case['format']),
            $case['note'],
        );
    }

    /**
     * Empty or missing formats are valid (they fall back to DEFAULT_FORMAT);
     * a non-empty format without {title} is not.
     */
    public function testIsValidFormat(): void
    {
        $this->assertTrue(TitleFormatter::isValidFormat(null));
        $this->assertTrue(TitleFormatter::isValidFormat(''));
        $this->assertTrue(TitleFormatter::isValidFormat('   '));
        $this->assertTrue(TitleFormatter::isValidFormat(TitleFormatter::DEFAULT_FORMAT));
        $this->assertTrue(TitleFormatter::isValidFormat('{title}'));
        $this->assertFalse(TitleFormatter::isValidFormat('{siteName} only'));
    }
}
