<?php

declare(strict_types=1);

namespace yii2\extensions\sentry\tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\EventId;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\Tracing\Transaction;
use Throwable;
use Yii;
use yii\base\Application;
use yii\log\Logger;
use yii\web\IdentityInterface;
use yii\web\Request;
use yii\web\User;
use yii2\extensions\sentry\SentryTarget;

class SentryTargetTracingTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalRequestConfig = [];

    protected function tearDown(): void
    {
        SentrySdk::getCurrentHub()->setSpan(null);

        if (!empty($this->originalRequestConfig)) {
            Yii::$app->set('request', $this->originalRequestConfig);
            $this->originalRequestConfig = [];
        }
    }

    /**
     * @param array<string, mixed> $extraConfig
     */
    private function createTracingTarget(array $extraConfig = []): SentryTarget
    {
        $config = array_merge([
            'dsn' => 'https://key@sentry.io/1',
            'tracing' => true,
            'clientOptions' => ['traces_sample_rate' => 1.0],
        ], $extraConfig);

        $target = new SentryTarget($config);
        $target->exportInterval = 100;

        return $target;
    }

    private function getPrivateMethod(SentryTarget $target, string $name): ReflectionMethod
    {
        return new ReflectionClass($target)->getMethod($name);
    }

    private function getPrivateProperty(SentryTarget $target, string $name): ReflectionProperty
    {
        return new ReflectionClass($target)->getProperty($name);
    }

    private function replaceRequestWithMock(Request $mockRequest): void
    {
        $this->originalRequestConfig = ['class' => get_class(Yii::$app->getRequest())];
        Yii::$app->set('request', $mockRequest);
    }

    public function testGetLevelsIncludesProfileWhenTracingEnabled(): void
    {
        $target = $this->createTracingTarget();
        $levels = $target->getLevels();

        $this->assertNotSame(0, $levels & Logger::LEVEL_PROFILE);
    }

    public function testGetLevelsExcludesProfileWhenTracingDisabled(): void
    {
        $target = new SentryTarget(['dsn' => 'https://key@sentry.io/1']);
        $target->exportInterval = 100;
        $target->setLevels(Logger::LEVEL_ERROR | Logger::LEVEL_WARNING);

        $levels = $target->getLevels();

        $this->assertSame(0, $levels & Logger::LEVEL_PROFILE);
    }

    public function testStartTransactionCreatesTransaction(): void
    {
        $target = $this->createTracingTarget();

        $startTransaction = $this->getPrivateMethod($target, 'startTransaction');
        $startTransaction->invoke($target);

        $transactionProp = $this->getPrivateProperty($target, 'transaction');
        $transaction = $transactionProp->getValue($target);

        $this->assertInstanceOf(Transaction::class, $transaction);
        $this->assertTrue($transaction->getSampled());
    }

    public function testStartTransactionReturnsEarlyWhenTracingDisabled(): void
    {
        $target = new SentryTarget([
            'dsn' => 'https://key@sentry.io/1',
            'tracing' => true,
        ]);
        $target->exportInterval = 100;

        $startTransaction = $this->getPrivateMethod($target, 'startTransaction');
        $startTransaction->invoke($target);

        $transactionProp = $this->getPrivateProperty($target, 'transaction');
        $this->assertNull($transactionProp->getValue($target));
    }

    public function testStartTransactionDoesNotSetSpanWhenUnsampled(): void
    {
        $target = $this->createTracingTarget(['clientOptions' => ['traces_sample_rate' => 0.0]]);

        SentrySdk::getCurrentHub()->setSpan(null);

        $startTransaction = $this->getPrivateMethod($target, 'startTransaction');
        $startTransaction->invoke($target);

        $transactionProp = $this->getPrivateProperty($target, 'transaction');
        $transaction = $transactionProp->getValue($target);

        $this->assertInstanceOf(Transaction::class, $transaction);
        $this->assertFalse($transaction->getSampled());
        $this->assertNull(SentrySdk::getCurrentHub()->getSpan());
    }

    public function testStartTransactionSetsSpanOnHubWhenSampled(): void
    {
        $target = $this->createTracingTarget();

        SentrySdk::getCurrentHub()->setSpan(null);

        $startTransaction = $this->getPrivateMethod($target, 'startTransaction');
        $startTransaction->invoke($target);

        $span = SentrySdk::getCurrentHub()->getSpan();
        $this->assertInstanceOf(Transaction::class, $span);
    }

    public function testConstructorRegistersEventHandlerWhenTracingEnabled(): void
    {
        $target = $this->createTracingTarget();

        Yii::$app->trigger(Application::EVENT_BEFORE_REQUEST);

        $transactionProp = $this->getPrivateProperty($target, 'transaction');
        $this->assertInstanceOf(Transaction::class, $transactionProp->getValue($target));
    }

    public function testConstructorDoesNotRegisterEventHandlerWhenTracingDisabled(): void
    {
        $target = new SentryTarget([
            'dsn' => 'https://key@sentry.io/1',
            'tracing' => false,
        ]);
        $target->exportInterval = 100;

        Yii::$app->trigger(Application::EVENT_BEFORE_REQUEST);

        $transactionProp = $this->getPrivateProperty($target, 'transaction');
        $this->assertNull($transactionProp->getValue($target));
    }

    public function testProcessTracingMessagesWithSampledTransaction(): void
    {
        $target = $this->createTracingTarget();

        $startTransaction = $this->getPrivateMethod($target, 'startTransaction');
        $startTransaction->invoke($target);

        $timestamp = microtime(true);
        $messages = [
            ['db-query', Logger::LEVEL_PROFILE_BEGIN, 'db', $timestamp, []],
            ['db-query', Logger::LEVEL_PROFILE_END, 'db', $timestamp + 0.1, []],
        ];

        $processTracing = $this->getPrivateMethod($target, 'processTracingMessages');
        $processTracing->invoke($target, $messages);

        $transactionProp = $this->getPrivateProperty($target, 'transaction');
        $this->assertNull($transactionProp->getValue($target));
    }

    public function testProcessTracingMessagesWithNestedSpans(): void
    {
        $target = $this->createTracingTarget();

        $startTransaction = $this->getPrivateMethod($target, 'startTransaction');
        $startTransaction->invoke($target);

        $timestamp = microtime(true);
        $messages = [
            ['parent-op', Logger::LEVEL_PROFILE_BEGIN, 'app', $timestamp, []],
            ['child-op', Logger::LEVEL_PROFILE_BEGIN, 'db', $timestamp + 0.01, []],
            ['child-op', Logger::LEVEL_PROFILE_END, 'db', $timestamp + 0.05, []],
            ['parent-op', Logger::LEVEL_PROFILE_END, 'app', $timestamp + 0.1, []],
        ];

        $processTracing = $this->getPrivateMethod($target, 'processTracingMessages');
        $processTracing->invoke($target, $messages);

        $transactionProp = $this->getPrivateProperty($target, 'transaction');
        $this->assertNull($transactionProp->getValue($target));
    }

    public function testProcessTracingMessagesReturnsEarlyWhenTracingDisabled(): void
    {
        $target = new SentryTarget([
            'dsn' => 'https://key@sentry.io/1',
            'tracing' => true,
        ]);
        $target->exportInterval = 100;

        $timestamp = microtime(true);
        $messages = [
            ['db-query', Logger::LEVEL_PROFILE_BEGIN, 'db', $timestamp, []],
        ];

        $processTracing = $this->getPrivateMethod($target, 'processTracingMessages');
        $processTracing->invoke($target, $messages);

        $transactionProp = $this->getPrivateProperty($target, 'transaction');
        $this->assertNull($transactionProp->getValue($target));
    }

    public function testProcessTracingMessagesReturnsEarlyWhenTransactionNotSampled(): void
    {
        $target = $this->createTracingTarget(['clientOptions' => ['traces_sample_rate' => 0.0]]);

        $startTransaction = $this->getPrivateMethod($target, 'startTransaction');
        $startTransaction->invoke($target);

        $timestamp = microtime(true);
        $messages = [
            ['db-query', Logger::LEVEL_PROFILE_BEGIN, 'db', $timestamp, []],
            ['db-query', Logger::LEVEL_PROFILE_END, 'db', $timestamp + 0.1, []],
        ];

        $processTracing = $this->getPrivateMethod($target, 'processTracingMessages');
        $processTracing->invoke($target, $messages);

        $transactionProp = $this->getPrivateProperty($target, 'transaction');
        $this->assertInstanceOf(Transaction::class, $transactionProp->getValue($target));
    }

    public function testProcessTracingMessagesStartsTransactionIfNull(): void
    {
        $target = $this->createTracingTarget();

        $transactionProp = $this->getPrivateProperty($target, 'transaction');
        $this->assertNull($transactionProp->getValue($target));

        $timestamp = microtime(true);
        $messages = [
            ['db-query', Logger::LEVEL_PROFILE_BEGIN, 'db', $timestamp, []],
            ['db-query', Logger::LEVEL_PROFILE_END, 'db', $timestamp + 0.1, []],
        ];

        $processTracing = $this->getPrivateMethod($target, 'processTracingMessages');
        $processTracing->invoke($target, $messages);

        $this->assertNull($transactionProp->getValue($target));
    }

    public function testProcessTracingMessagesRestoresPreviousSpan(): void
    {
        $target = $this->createTracingTarget();

        $startTransaction = $this->getPrivateMethod($target, 'startTransaction');
        $startTransaction->invoke($target);

        $spanBeforeProcess = SentrySdk::getCurrentHub()->getSpan();

        $timestamp = microtime(true);
        $messages = [
            ['db-query', Logger::LEVEL_PROFILE_BEGIN, 'db', $timestamp, []],
            ['db-query', Logger::LEVEL_PROFILE_END, 'db', $timestamp + 0.1, []],
        ];

        $processTracing = $this->getPrivateMethod($target, 'processTracingMessages');
        $processTracing->invoke($target, $messages);

        $spanAfterProcess = SentrySdk::getCurrentHub()->getSpan();
        $this->assertSame($spanBeforeProcess, $spanAfterProcess);
    }

    public function testResolveTransactionNameForConsoleAppWithRoute(): void
    {
        $target = $this->createTracingTarget();

        Yii::$app->requestedRoute = 'test/route';

        $method = $this->getPrivateMethod($target, 'resolveTransactionName');
        $result = $method->invoke($target);

        $this->assertSame('cli test/route', $result);
    }

    public function testResolveTransactionNameForConsoleAppWithoutRoute(): void
    {
        $target = $this->createTracingTarget();

        Yii::$app->requestedRoute = '';

        $method = $this->getPrivateMethod($target, 'resolveTransactionName');
        $result = $method->invoke($target);

        $this->assertSame('cli unknown', $result);
    }

    public function testResolveTransactionNameForWebApp(): void
    {
        $target = $this->createTracingTarget();

        $stubRequest = $this->createStub(Request::class);
        $stubRequest->method('getMethod')->willReturn('POST');
        $stubRequest->method('getPathInfo')->willReturn('/api/users');
        $this->replaceRequestWithMock($stubRequest);

        $method = $this->getPrivateMethod($target, 'resolveTransactionName');
        $result = $method->invoke($target);

        $this->assertSame('POST /api/users', $result);
    }

    public function testResolveTransactionNameHandlesException(): void
    {
        $target = $this->createTracingTarget();

        $originalApp = Yii::$app;
        Yii::$app = null;

        $method = $this->getPrivateMethod($target, 'resolveTransactionName');
        $result = $method->invoke($target);

        $this->assertSame('yii2-request', $result);

        Yii::$app = $originalApp;
    }

    public function testResolveTransactionOpForConsoleApp(): void
    {
        $target = $this->createTracingTarget();

        $method = $this->getPrivateMethod($target, 'resolveTransactionOp');
        $result = $method->invoke($target);

        $this->assertSame('cli.server', $result);
    }

    public function testResolveTransactionOpForWebApp(): void
    {
        $target = $this->createTracingTarget();

        $stubRequest = $this->createStub(Request::class);
        $this->replaceRequestWithMock($stubRequest);

        $method = $this->getPrivateMethod($target, 'resolveTransactionOp');
        $result = $method->invoke($target);

        $this->assertSame('http.server', $result);
    }

    public function testResolveTransactionOpHandlesException(): void
    {
        $target = $this->createTracingTarget();

        $originalApp = Yii::$app;
        Yii::$app = null;

        $method = $this->getPrivateMethod($target, 'resolveTransactionOp');
        $result = $method->invoke($target);

        $this->assertSame('http.server', $result);

        Yii::$app = $originalApp;
    }

    public function testExportWithTracingSeparatesProfileMessages(): void
    {
        $capturedEvents = [];
        $target = new SentryTarget([
            'dsn' => 'https://key@sentry.io/1',
            'tracing' => true,
            'clientOptions' => [
                'traces_sample_rate' => 1.0,
                'before_send' => function (Event $event) use (&$capturedEvents) {
                    $capturedEvents[] = $event;
                    return null;
                },
                'before_send_transaction' => function (Event $event) use (&$capturedEvents) {
                    $capturedEvents[] = $event;
                    return null;
                },
            ],
        ]);
        $target->exportInterval = 100;
        $target->setLevels(Logger::LEVEL_ERROR | Logger::LEVEL_PROFILE);

        $timestamp = microtime(true);
        $messages = [
            ['regular error', Logger::LEVEL_ERROR, 'app', $timestamp, []],
            ['db-query', Logger::LEVEL_PROFILE_BEGIN, 'db', $timestamp + 0.01, []],
            ['db-query', Logger::LEVEL_PROFILE_END, 'db', $timestamp + 0.02, []],
        ];

        $target->collect($messages, true);

        $this->assertGreaterThanOrEqual(2, count($capturedEvents));
        $regularMessages = array_filter($capturedEvents, static fn(Event $e) => $e->getMessage() === 'regular error');
        $this->assertCount(1, $regularMessages);
    }

    public function testExportWithTracingDisabledProcessesProfileAsRegular(): void
    {
        $capturedEvents = [];
        $target = new SentryTarget([
            'dsn' => 'https://key@sentry.io/1',
            'tracing' => false,
            'clientOptions' => [
                'before_send' => function (Event $event) use (&$capturedEvents) {
                    $capturedEvents[] = $event;
                    return null;
                },
            ],
        ]);
        $target->exportInterval = 100;
        $target->setLevels(Logger::LEVEL_INFO | Logger::LEVEL_PROFILE);

        $timestamp = microtime(true);
        $messages = [
            ['info message', Logger::LEVEL_INFO, 'app', $timestamp, []],
            ['profile message', Logger::LEVEL_PROFILE_BEGIN, 'db', $timestamp + 0.01, []],
        ];

        $target->collect($messages, true);

        $this->assertCount(2, $capturedEvents);
    }

    public function testGetLogLevelForProfileBegin(): void
    {
        $target = new SentryTarget(['dsn' => 'https://key@sentry.io/1']);

        $method = $this->getPrivateMethod($target, 'getLogLevel');
        $result = $method->invoke($target, Logger::LEVEL_PROFILE_BEGIN);

        $this->assertEquals(Severity::debug(), $result);
    }

    public function testGetLogLevelForProfileEnd(): void
    {
        $target = new SentryTarget(['dsn' => 'https://key@sentry.io/1']);

        $method = $this->getPrivateMethod($target, 'getLogLevel');
        $result = $method->invoke($target, Logger::LEVEL_PROFILE_END);

        $this->assertEquals(Severity::debug(), $result);
    }

    public function testGetLogLevelForProfile(): void
    {
        $target = new SentryTarget(['dsn' => 'https://key@sentry.io/1']);

        $method = $this->getPrivateMethod($target, 'getLogLevel');
        $result = $method->invoke($target, Logger::LEVEL_PROFILE);

        $this->assertEquals(Severity::debug(), $result);
    }

    public function testGetLogLevelForTrace(): void
    {
        $target = new SentryTarget(['dsn' => 'https://key@sentry.io/1']);

        $method = $this->getPrivateMethod($target, 'getLogLevel');
        $result = $method->invoke($target, Logger::LEVEL_TRACE);

        $this->assertEquals(Severity::debug(), $result);
    }

    public function testRunExtraCallbackWithCallable(): void
    {
        $target = new SentryTarget([
            'dsn' => 'https://key@sentry.io/1',
            'extraCallback' => function ($text, $extra) {
                $extra['injected'] = 'value';

                return $extra;
            },
        ]);

        $data = [
            'message' => 'test',
            'tags' => [],
            'extra' => ['original' => 'data'],
            'userData' => [],
        ];

        $result = $target->runExtraCallback('test text', $data);

        $this->assertSame('value', $result['extra']['injected']);
        $this->assertSame('data', $result['extra']['original']);
    }

    public function testRunExtraCallbackWithoutCallable(): void
    {
        $target = new SentryTarget(['dsn' => 'https://key@sentry.io/1']);

        $data = [
            'message' => 'test',
            'tags' => [],
            'extra' => ['original' => 'data'],
            'userData' => [],
        ];

        $result = $target->runExtraCallback('test text', $data);

        $this->assertSame($data, $result);
    }

    public function testExportWithUserDataIp(): void
    {
        $capturedEvents = [];
        $target = new SentryTarget([
            'dsn' => 'https://key@sentry.io/1',
            'clientOptions' => [
                'before_send' => function (Event $event) use (&$capturedEvents) {
                    $capturedEvents[] = $event;
                    return null;
                },
            ],
        ]);
        $target->exportInterval = 100;
        $target->setLevels(Logger::LEVEL_INFO);

        $stubRequest = $this->createStub(Request::class);
        $stubRequest->method('getUserIP')->willReturn('192.168.1.1');
        $this->replaceRequestWithMock($stubRequest);

        $target->collect([['test message', Logger::LEVEL_INFO, 'app', microtime(true), []]], true);

        $this->assertCount(1, $capturedEvents);
    }

    public function testExportWithUserIdentity(): void
    {
        $capturedEvents = [];
        $target = new SentryTarget([
            'dsn' => 'https://key@sentry.io/1',
            'clientOptions' => [
                'before_send' => function (Event $event) use (&$capturedEvents) {
                    $capturedEvents[] = $event;
                    return null;
                },
            ],
        ]);
        $target->exportInterval = 100;
        $target->setLevels(Logger::LEVEL_INFO);

        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('getId')->willReturn(42);

        $user = $this->createStub(User::class);
        $user->method('getIdentity')->willReturn($identity);

        Yii::$app->set('user', $user);
        Yii::$app->get('user');

        $target->collect([['test message', Logger::LEVEL_INFO, 'app', microtime(true), []]], true);

        $this->assertCount(1, $capturedEvents);
    }

    public function testExportWithUserIdentityException(): void
    {
        $capturedEvents = [];
        $target = new SentryTarget([
            'dsn' => 'https://key@sentry.io/1',
            'clientOptions' => [
                'before_send' => function (Event $event) use (&$capturedEvents) {
                    $capturedEvents[] = $event;
                    return null;
                },
            ],
        ]);
        $target->exportInterval = 100;
        $target->setLevels(Logger::LEVEL_INFO);

        $user = $this->createStub(User::class);
        $user->method('getIdentity')->willThrowException(new RuntimeException('identity error'));

        Yii::$app->set('user', $user);
        Yii::$app->get('user');

        $target->collect([['test message', Logger::LEVEL_INFO, 'app', microtime(true), []]], true);

        $this->assertCount(1, $capturedEvents);
    }

    public function testExportWithTagsInText(): void
    {
        $capturedEvents = [];
        $target = new SentryTarget([
            'dsn' => 'https://key@sentry.io/1',
            'clientOptions' => [
                'before_send' => function (Event $event) use (&$capturedEvents) {
                    $capturedEvents[] = $event;
                    return null;
                },
            ],
        ]);
        $target->exportInterval = 100;
        $target->setLevels(Logger::LEVEL_INFO);

        $logData = [
            'msg' => 'tagged message',
            'tags' => ['env' => 'production', 'feature' => 'new-ui'],
            'extra_key' => 'extra_value',
        ];

        $target->collect([[$logData, Logger::LEVEL_INFO, 'app', microtime(true), []]], true);

        $this->assertCount(1, $capturedEvents);
        $this->assertSame('tagged message', $capturedEvents[0]->getMessage());
    }

    public function testExportWithThrowableText(): void
    {
        $capturedExceptions = [];
        $target = new SentryTarget([
            'dsn' => 'https://key@sentry.io/1',
            'clientOptions' => [
                'before_send' => function (Event $event): ?Event {
                    return null;
                },
            ],
        ]);
        $target->exportInterval = 100;
        $target->setLevels(Logger::LEVEL_ERROR);

        $exception = new RuntimeException('direct throwable');

        $client = SentrySdk::getCurrentHub()->getClient();
        $options = $client?->getOptions();
        $stubClient = $this->createStub(ClientInterface::class);
        $stubClient->method('getOptions')->willReturn($options);
        $stubClient->method('captureException')->willReturnCallback(function (Throwable $ex) use (&$capturedExceptions): EventId {
            $capturedExceptions[] = $ex;
            return EventId::generate();
        });
        $stubClient->method('captureEvent')->willReturn(EventId::generate());
        SentrySdk::getCurrentHub()->bindClient($stubClient);

        $target->collect([[$exception, Logger::LEVEL_ERROR, 'app', microtime(true), []]], true);

        $this->assertCount(1, $capturedExceptions);
        $this->assertSame($exception, $capturedExceptions[0]);
    }

    public function testExportWithContextDisabled(): void
    {
        $capturedEvents = [];
        $target = new SentryTarget([
            'dsn' => 'https://key@sentry.io/1',
            'context' => false,
            'clientOptions' => [
                'before_send' => function (Event $event) use (&$capturedEvents) {
                    $capturedEvents[] = $event;
                    return null;
                },
            ],
        ]);
        $target->exportInterval = 100;
        $target->setLevels(Logger::LEVEL_INFO);

        $target->collect([['test message', Logger::LEVEL_INFO, 'app', microtime(true), []]], true);

        $this->assertCount(1, $capturedEvents);
    }

    public function testExportWithFalseTagValue(): void
    {
        $capturedEvents = [];
        $target = new SentryTarget([
            'dsn' => 'https://key@sentry.io/1',
            'clientOptions' => [
                'before_send' => function (Event $event) use (&$capturedEvents) {
                    $capturedEvents[] = $event;
                    return null;
                },
            ],
        ]);
        $target->exportInterval = 100;
        $target->setLevels(Logger::LEVEL_INFO);

        $logData = [
            'msg' => 'message with false tag',
            'tags' => ['active' => 'yes', 'deleted' => ''],
        ];

        $target->collect([[$logData, Logger::LEVEL_INFO, 'app', microtime(true), []]], true);

        $this->assertCount(1, $capturedEvents);
    }

    public function testExportWithExceptionInTextArray(): void
    {
        $capturedEvents = [];
        $capturedHints = [];
        $target = new SentryTarget([
            'dsn' => 'https://key@sentry.io/1',
            'clientOptions' => [
                'before_send' => function (Event $event) use (&$capturedEvents) {
                    $capturedEvents[] = $event;
                    return null;
                },
            ],
        ]);
        $target->exportInterval = 100;
        $target->setLevels(Logger::LEVEL_ERROR);

        $exception = new RuntimeException('embedded exception');
        $logData = [
            'msg' => 'error with exception',
            'exception' => $exception,
        ];

        $client = SentrySdk::getCurrentHub()->getClient();
        $options = $client?->getOptions();
        $stubClient = $this->createStub(ClientInterface::class);
        $stubClient->method('getOptions')->willReturn($options);
        $stubClient->method('captureEvent')->willReturnCallback(function (Event $event, ?EventHint $hint = null) use (&$capturedEvents, &$capturedHints): EventId {
            $capturedEvents[] = $event;
            $capturedHints[] = $hint;
            return EventId::generate();
        });
        SentrySdk::getCurrentHub()->bindClient($stubClient);

        $target->collect([[$logData, Logger::LEVEL_ERROR, 'app', microtime(true), []]], true);

        $this->assertCount(1, $capturedEvents);
        $this->assertSame('error with exception', $capturedEvents[0]->getMessage());
        $hint = $capturedHints[0];
        $this->assertNotNull($hint);
        $this->assertSame($exception, $hint->exception);
    }
}
