<!-- markdownlint-disable MD041 -->
<p align="center">
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://www.yiiframework.com/image/design/logo/yii3_full_for_dark.svg">
        <source media="(prefers-color-scheme: light)" srcset="https://www.yiiframework.com/image/design/logo/yii3_full_for_light.svg">
        <img src="https://www.yiiframework.com/image/design/logo/yii3_full_for_dark.svg" alt="Yii Framework" width="80%">
    </picture>
    <h1 align="center">Sentry</h1>
    <br>
</p>
<!-- markdownlint-enable MD041 -->

<p align="center">
    <a href="https://github.com/yii2-extensions/sentry/actions/workflows/build.yml" target="_blank">
        <img src="https://img.shields.io/github/actions/workflow/status/yii2-extensions/sentry/build.yml?style=for-the-badge&logo=github&label=PHPUnit" alt="PHPUnit">
    </a>
    <a href="https://codecov.io/github/yii2-extensions/sentry" target="_blank">
        <img src="https://img.shields.io/codecov/c/github/yii2-extensions/sentry.svg?style=for-the-badge&logo=codecov&logoColor=white&label=Coverage" alt="CodeCoverage">
    </a>
    <a href="https://github.com/yii2-extensions/sentry/actions/workflows/static.yml" target="_blank">
        <img src="https://img.shields.io/github/actions/workflow/status/yii2-extensions/sentry/static.yml?style=for-the-badge&logo=github&label=PHPStan" alt="PHPStan">
    </a>
</p>

> [!NOTE]
> Continued development of https://github.com/notamedia/yii2-sentry

## Requirements

- PHP >= 8.4
- `ext-excimer` for profiling metrics

## Installation

```bash
composer require yii2-extensions/sentry
```

Add target class in the application config:

```php
return [
    'components' => [
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                [
                    'class' => '\yii2\extensions\sentry\SentryTarget',
                    'dsn' => 'https://2682ybvhbs347:235vvgy465346@sentry.io/1',
                    'levels' => ['error', 'warning'],
                    // Write the context information (the default is true):
                    'context' => true,
                    // Additional options for `Sentry\init`:
                    'clientOptions' => ['release' => 'my-project-name@2.3.12']
                ],
            ],
        ],
    ],
];
```

## Usage

Writing simple message:

```php
\Yii::error('message', 'category');
```

Writing messages with extra data:

```php
\Yii::warning([
    'msg' => 'message',
    'extra' => 'value',
], 'category');
```

### Extra callback

`extraCallback` property can modify extra's data as callable function:

```php
    'targets' => [
        [
            'class' => '\yii2\extensions\sentry\SentryTarget',
            'dsn' => 'https://2682ybvhbs347:235vvgy465346@sentry.io/1',
            'levels' => ['error', 'warning'],
            'context' => true, // Write the context information. The default is true.
            'extraCallback' => function ($message, $extra) {
                // some manipulation with data
                $extra['some_data'] = \Yii::$app->someComponent->someMethod();
                return $extra;
            }
        ],
    ],
```

### Tags

Writing messages with additional tags. If need to add additional tags for event, add `tags` key in message. Tags are various key/value pairs that get assigned to an event, and can later be used as a breakdown or quick access to finding related events.

Example:

```php
\Yii::warning([
    'msg' => 'message',
    'extra' => 'value',
    'tags' => [
        'extraTagKey' => 'extraTagValue',
    ]
], 'category');
```

More about tags see https://docs.sentry.io/learn/context/#tagging-events

### Additional context

You can add additional context (such as user information, fingerprint, etc) by calling `\Sentry\configureScope()` before logging.
For example in main configuration on `beforeAction` event (real place will dependant on your project):
```php
return [
    // ...
    'on beforeAction' => function (\yii\base\ActionEvent $event) {
        /** @var \yii\web\User $user */
        $user = Yii::$app->has('user', true) ? Yii::$app->get('user', false) : null;
        if ($user && ($identity = $user->getIdentity(false))) {
            \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($identity) {
                $scope->setUser([
                    // User ID and IP will be added by logger automatically
                    'username' => $identity->username,
                    'email' => $identity->email,
                ]);
            });
        }
    
        return $event->isValid;
    },
    // ...
];
```

## Log levels

Yii2 log levels converts to Sentry levels:

```php
\yii\log\Logger::LEVEL_ERROR => 'error',
\yii\log\Logger::LEVEL_WARNING => 'warning',
\yii\log\Logger::LEVEL_INFO => 'info',
\yii\log\Logger::LEVEL_TRACE => 'debug',
\yii\log\Logger::LEVEL_PROFILE_BEGIN => 'debug',
\yii\log\Logger::LEVEL_PROFILE_END => 'debug',
```

## Performance and profiling

```php
'targets' => [
    [
        'class' => SentryTarget::class,
        'tracing' => true,
        'clientOptions' => [
            'traces_sample_rate' => 1.0
        ],
    ],
], 
```

### Enable tracing

```php
'clientOptions' => [
    'traces_sample_rate' => 1.0,
],
```

### Enable profiling

![image](https://docs.sentry.io/mdx-images/profiling-page-aggregate-flamegraph-AVP2BMUE.png)

> [!NOTE]
> For the profiler to work, the [Excimer](https://pecl.php.net/package/excimer) extension must be installed.

```php
'clientOptions' => [
    // Set a sampling rate for profiling - this is relative to traces_sample_rate
    'profiles_sample_rate' => 1.0,
],
```
