<?php

/**
 * Unit-suite bootstrap. Loads the plugin's Composer autoloader plus Yii's and
 * Craft's class façades so pure logic that calls `Craft::t()` resolves without
 * booting a full Craft application. With no app present, `Craft::t()` falls
 * back to placeholder interpolation on the source string — exactly what unit
 * assertions expect. App-backed behaviour stays in the integration suite.
 */

$root = dirname(__DIR__, 2);

require_once $root . '/vendor/autoload.php';
require_once $root . '/vendor/yiisoft/yii2/Yii.php';
require_once $root . '/vendor/craftcms/cms/src/Craft.php';
