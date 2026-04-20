<?php

declare(strict_types=1);

use yii\console\Application;

return [
    'id' => 'sentry-tests',
    'class' => Application::class,
    'basePath' => Yii::getAlias('@tests'),
    'runtimePath' => Yii::getAlias('@tests/_output'),
    'bootstrap' => [],
];
