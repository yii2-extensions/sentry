<?php

// phpcs:disable PSR12.Files.FileHeader.SpacingAfterTagBlock
// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

declare(strict_types=1);

error_reporting(-1);

define('YII_ENABLE_ERROR_HANDLER', false);
define('YII_DEBUG', true);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

Yii::setAlias('@tests', __DIR__);

new \yii\console\Application([
    'id' => 'sentry-tests',
    'basePath' => __DIR__,
    'runtimePath' => __DIR__ . '/_output',
    'bootstrap' => [],
]);
