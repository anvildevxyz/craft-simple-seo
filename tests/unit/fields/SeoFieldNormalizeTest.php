<?php

namespace anvildev\simpleseo\tests\unit\fields;

use anvildev\simpleseo\fields\data\SeoData;
use anvildev\simpleseo\fields\SeoField;
use PHPUnit\Framework\TestCase;

/**
 * Normalization robustness: every input shape the field can receive — POST
 * arrays, DB JSON, element-select ID arrays, null, junk — must produce a
 * well-formed SeoData without throwing. Special characters (%, quotes, emoji,
 * multibyte) round-trip untouched; ether/seo threw JS/PHP errors on exactly
 * these inputs (ethercreative/seo#254, #324, #265).
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class SeoFieldNormalizeTest extends TestCase
{
    // Public Methods
    // =========================================================================

    /**
     * Null (entries that predate the field) normalizes to graceful defaults.
     */
    public function testNullNormalizesToDefaults(): void
    {
        $value = (new SeoField())->normalizeValue(null);

        $this->assertInstanceOf(SeoData::class, $value);
        $this->assertNull($value->title);
        $this->assertNull($value->description);
        $this->assertNull($value->socialImageId);
        $this->assertFalse($value->noindex);
        $this->assertFalse($value->nofollow);
        $this->assertNull($value->canonical);
        $this->assertTrue($value->isEmpty());
    }

    /**
     * Special characters survive normalization byte-for-byte.
     */
    public function testSpecialCharactersSurvive(): void
    {
        $title = '100% Zürich — 🚀 "Quotes" & <tags> \' %s';
        $description = 'Ümläute, emoji 🎯🎪, 50% off & "nested \'quotes\'" — fin.';

        $value = (new SeoField())->normalizeValue([
            'title' => $title,
            'description' => $description,
        ]);

        $this->assertSame($title, $value->title);
        $this->assertSame($description, $value->description);
    }

    /**
     * DB JSON strings and POST arrays normalize identically.
     */
    public function testJsonStringAndArrayNormalizeIdentically(): void
    {
        $field = new SeoField();
        $data = [
            'title' => 'Tïtle 🚀',
            'description' => 'Desc',
            'socialImageId' => 42,
            'noindex' => true,
            'nofollow' => false,
            'canonical' => 'https://example.com/page',
        ];

        $fromArray = $field->normalizeValue($data);
        $fromJson = $field->normalizeValue(json_encode($data));

        $this->assertSame($field->serializeValue($fromArray), $field->serializeValue($fromJson));
        $this->assertSame(42, $fromJson->socialImageId);
        $this->assertTrue($fromJson->noindex);
    }

    /**
     * The element-select input posts asset IDs as arrays; blank/junk collapses
     * to null instead of erroring.
     */
    public function testSocialImageIdShapes(): void
    {
        $field = new SeoField();

        $this->assertSame(7, $field->normalizeValue(['socialImageId' => ['7']])->socialImageId);
        $this->assertSame(7, $field->normalizeValue(['socialImageId' => 7])->socialImageId);
        $this->assertNull($field->normalizeValue(['socialImageId' => []])->socialImageId);
        $this->assertNull($field->normalizeValue(['socialImageId' => ''])->socialImageId);
        $this->assertNull($field->normalizeValue(['socialImageId' => 'junk'])->socialImageId);
        $this->assertNull($field->normalizeValue(['socialImageId' => -3])->socialImageId);
    }

    /**
     * Blank and whitespace-only strings collapse to null so isEmpty() is
     * accurate and serializeValue() can keep untouched entries at NULL.
     */
    public function testBlankStringsCollapseToNull(): void
    {
        $field = new SeoField();
        $value = $field->normalizeValue([
            'title' => '   ',
            'description' => '',
            'canonical' => "\t\n",
        ]);

        $this->assertTrue($value->isEmpty());
        $this->assertNull($field->serializeValue($value));
    }

    /**
     * Junk input (wrong types entirely) degrades to defaults, never throws.
     */
    public function testJunkInputDegradesToDefaults(): void
    {
        $field = new SeoField();

        $this->assertTrue($field->normalizeValue('not json at all')->isEmpty());
        $this->assertTrue($field->normalizeValue(12345)->isEmpty());
        $this->assertTrue($field->normalizeValue(['title' => ['array' => 'not string']])->isEmpty());
    }

    /**
     * An existing SeoData instance passes through untouched.
     */
    public function testSeoDataPassesThrough(): void
    {
        $field = new SeoField();
        $data = new SeoData(['title' => 'Kept']);

        $this->assertSame($data, $field->normalizeValue($data));
    }
}
