/**
 * Node tests for the JS title-format mirror, driven by the shared vector file
 * tests/fixtures/title-format-cases.json — the same vectors drive the PHP
 * side (tests/unit/helpers/TitleFormatterTest.php), so the two
 * implementations cannot drift apart case-by-case.
 *
 * Run: node tests/js/preview-format.test.js
 */
'use strict';

const assert = require('node:assert');
const cases = require('../fixtures/title-format-cases.json');
const { formatSeoTitle, DEFAULT_TITLE_FORMAT } = require('../../src/web/assets/seofield/dist/seo-field.js');

assert.strictEqual(DEFAULT_TITLE_FORMAT, '{title} - {siteName}');

for (const c of cases) {
  assert.strictEqual(formatSeoTitle(c.title, c.fallback, c.siteName, c.format), c.expected, c.note);
}

// JS-only: null/undefined inputs never throw (the DOM can hand us anything).
assert.strictEqual(formatSeoTitle(undefined, undefined, undefined, undefined), '');

console.log('preview-format: OK (' + (cases.length + 2) + ' assertions)');
