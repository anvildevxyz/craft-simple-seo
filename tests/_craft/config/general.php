<?php

return [
    'devMode' => true,
    // Production always has a securityKey; set one here so tests exercise the
    // real keyed-hash / encryption paths instead of degraded fallbacks.
    'securityKey' => 'simple-seo-test-security-key-0123456789',
];
