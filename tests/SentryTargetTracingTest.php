<?php

declare(strict_types=1);

namespace yii2\extensions\sentry\tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\EventId;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Throwable;
use Yii;
use yii\base\Application;
use yii\helpers\ArrayHelper;
use yii\log\Logger;
use yii\web\HeaderCollection;
use yii\web\IdentityInterface;
use yii\web\Request;
use yii\web\Response;
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

        Yii::$app->off(Application::EVENT_BEFORE_REQUEST);
        Yii::$app->off(Application::EVENT_AFTER_REQUEST);
    }

    /**
     * @param array<string, mixed> $extraConfig
     */
    private function createTracingTarget(array $extraConfig = []): SentryTarget
    {
        $config = ArrayHelper::merge([
            'dsn' => 'https://key@sentry.io/1',
            'tracing' => true,
            'clientOptions' => [
                'traces_sample_rate' => 1.0,
                'before_send_transaction' => static fn(Event $event): ?Event => null,
            ],
        ], $extraConfig);

        $target = new SentryTarget($config);
        $target->exportInterval = 100;

        return $target;
    }

    private function replaceRequestWithMock(Request $mockRequest): void
    {
        $this->originalRequestConfig = ['class' => get_class(Yii::$app->getRequest())];
        Yii::$app->set('request', $mockRequest);
    }

    public function testConstructorMergesDefaultSampleRatesWhenTracingEnabled(): void
    {
        new SentryTarget([
            'dsn' => 'https://key@sentry.io/1',
            'tracing' => true,
        ]);

        $client = SentrySdk::getCurrentHub()->getClient();
        $options = $client?->getOptions();
        $this->assertNotNull($options);

        $this->assertSame(1.0, $options->getTracesSampleRate());
        $this->assertSame(1.0, $options->getProfilesSampleRate());
    }

    public function testConstructorClientOptionsOverrideDefaultSampleRates(): void
    {
        new SentryTarget([
            'dsn' => 'https://key@sentry.io/1',
            'tracing' => true,
            'clientOptions' => [
                'traces_sample_rate' => 0.5,
                'profiles_sample_rate' => 0.3,
            ],
        ]);

        $client = SentrySdk::getCurrentHub()->getClient();
        $options = $client?->getOptions();
        $this->assertNotNull($options);

        $this->assertSame(0.5, $options->getTracesSampleRate());
        $this->assertSame(0.3, $options->getProfilesSampleRate());
    }

    public function testBeforeRequestStartsTransactionWithContinueTrace(): void
    {
        $this->createTracingTarget();

        $headers = new HeaderCollection();
        $headers->set('sentry-trace', '1234567890abcdef1234567890abcdef-1234567890abcdef-1');
        $headers->set('baggage', 'sentry-trace_id=1234567890abcdef1234567890abcdef');

        $stubRequest = $this->createStub(Request::class);
        $stubRequest->method('getHeaders')->willReturn($headers);
        $stubRequest->method('getMethod')->willReturn('GET');
        $stubRequest->method('getPathInfo')->willReturn('/api/test');
        $this->replaceRequestWithMock($stubRequest);

        Yii::$app->trigger(Application::EVENT_BEFORE_REQUEST);

        $span = SentrySdk::getCurrentHub()->getSpan();
        $this->assertInstanceOf(Transaction::class, $span);
        $this->assertSame('GET /api/test', $span->getName());
        $this->assertSame('http.server', $span->getOp());
    }

    public function testBeforeRequestStartsTransactionWithContinueTraceWithoutBaggage(): void
    {
        $this->createTracingTarget();

        $headers = new HeaderCollection();
        $headers->set('sentry-trace', '1234567890abcdef1234567890abcdef-1234567890abcdef-1');

        $stubRequest = $this->createStub(Request::class);
        $stubRequest->method('getHeaders')->willReturn($headers);
        $stubRequest->method('getMethod')->willReturn('GET');
        $stubRequest->method('getPathInfo')->willReturn('/api/test');
        $this->replaceRequestWithMock($stubRequest);

        Yii::$app->trigger(Application::EVENT_BEFORE_REQUEST);

        $span = SentrySdk::getCurrentHub()->getSpan();
        $this->assertInstanceOf(Transaction::class, $span);
        $this->assertSame('GET /api/test', $span->getName());
        $this->assertSame('http.server', $span->getOp());
    }

    public function testBeforeRequestStartsTransactionWithoutContinueTrace(): void
    {
        $this->createTracingTarget();

        Yii::$app->trigger(Application::EVENT_BEFORE_REQUEST);

        $span = SentrySdk::getCurrentHub()->getSpan();
        $this->assertInstanceOf(Transaction::class, $span);
        $this->assertSame('<unknown>', $span->getName());
        $this->assertSame('http.server', $span->getOp());
    }

    public function testAfterRequestFinishesTransactionWithRoute(): void
    {
        $this->createTracingTarget();

        $stubRequest = $this->createStub(Request::class);
        $stubRequest->method('getHeaders')->willReturn(new HeaderCollection());
        $stubRequest->method('getMethod')->willReturn('POST');
        $stubRequest->method('getPathInfo')->willReturn('/api/users');
        $this->replaceRequestWithMock($stubRequest);

        $stubResponse = $this->createStub(Response::class);
        $stubResponse->method('getStatusCode')->willReturn(200);
        Yii::$app->set('response', $stubResponse);

        Yii::$app->trigger(Application::EVENT_BEFORE_REQUEST);

        $transaction = SentrySdk::getCurrentHub()->getTransaction();
        $this->assertInstanceOf(Transaction::class, $transaction);
        $this->assertNull($transaction->getEndTimestamp());

        Yii::$app->requestedRoute = 'user/view';
        Yii::$app->trigger(Application::EVENT_AFTER_REQUEST);

        $this->assertNotNull($transaction->getEndTimestamp());
        $this->assertSame('user/view', $transaction->getName());
    }

    public function testAfterRequestFinishesTransactionWithUrlWhenNoRoute(): void
    {
        $this->createTracingTarget();

        $stubRequest = $this->createStub(Request::class);
        $stubRequest->method('getHeaders')->willReturn(new HeaderCollection());
        $stubRequest->method('getMethod')->willReturn('GET');
        $stubRequest->method('getPathInfo')->willReturn('/fallback');
        $this->replaceRequestWithMock($stubRequest);

        $stubResponse = $this->createStub(Response::class);
        $stubResponse->method('getStatusCode')->willReturn(404);
        Yii::$app->set('response', $stubResponse);

        Yii::$app->trigger(Application::EVENT_BEFORE_REQUEST);

        $transaction = SentrySdk::getCurrentHub()->getTransaction();
        $this->assertInstanceOf(Transaction::class, $transaction);

        Yii::$app->requestedRoute = '';
        Yii::$app->trigger(Application::EVENT_AFTER_REQUEST);

        $this->assertNotNull($transaction->getEndTimestamp());
        $this->assertSame('GET /fallback', $transaction->getName());
    }

    public function testAfterRequestSkipsWhenNoTransaction(): void
    {
        $this->createTracingTarget();

        SentrySdk::getCurrentHub()->setSpan(null);

        Yii::$app->trigger(Application::EVENT_AFTER_REQUEST);

        $this->assertNull(SentrySdk::getCurrentHub()->getTransaction());
    }

    public function testConstructorDoesNotRegisterEventHandlersWhenTracingDisabled(): void
    {
        $target = new SentryTarget([
            'dsn' => 'https://key@sentry.io/1',
            'tracing' => false,
        ]);
        $target->exportInterval = 100;

        $context = new TransactionContext();
        $context->setName('manual');
        $context->setSampled(true);
        $manualTransaction = \Sentry\startTransaction($context);
        SentrySdk::getCurrentHub()->setSpan($manualTransaction);

        Yii::$app->trigger(Application::EVENT_BEFORE_REQUEST);

        $transaction = SentrySdk::getCurrentHub()->getTransaction();
        $this->assertInstanceOf(Transaction::class, $transaction);
        $this->assertSame('manual', $transaction->getName());
    }

    public function testGetTransactionNameForConsoleApp(): void
    {
        $target = new SentryTarget([
            'dsn' => 'https://key@sentry.io/1',
            'tracing' => false,
        ]);

        $refClass = new ReflectionClass($target);
        $method = $refClass->getMethod('getTransactionName');

        $result = $method->invoke($target);

        $this->assertSame('<unknown>', $result);
    }

    public function testGetTransactionNameForWebApp(): void
    {
        $target = new SentryTarget([
            'dsn' => 'https://key@sentry.io/1',
            'tracing' => false,
        ]);

        $stubRequest = $this->createStub(Request::class);
        $stubRequest->method('getMethod')->willReturn('POST');
        $stubRequest->method('getPathInfo')->willReturn('/api/users');
        $this->replaceRequestWithMock($stubRequest);

        $refClass = new ReflectionClass($target);
        $method = $refClass->getMethod('getTransactionName');

        $result = $method->invoke($target);

        $this->assertSame('POST /api/users', $result);
    }

    public function testGetTransactionNameForWebAppWithEmptyPathInfo(): void
    {
        $target = new SentryTarget([
            'dsn' => 'https://key@sentry.io/1',
            'tracing' => false,
        ]);

        $stubRequest = $this->createStub(Request::class);
        $stubRequest->method('getMethod')->willReturn('GET');
        $stubRequest->method('getPathInfo')->willReturn('');
        $this->replaceRequestWithMock($stubRequest);

        $refClass = new ReflectionClass($target);
        $method = $refClass->getMethod('getTransactionName');

        $result = $method->invoke($target);

        $this->assertSame('GET /', $result);
    }

    public function testExportWithTracingDoesNotAffectRegularMessages(): void
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
        $target->setLevels(Logger::LEVEL_ERROR);

        $timestamp = microtime(true);
        $messages = [
            ['regular error', Logger::LEVEL_ERROR, 'app', $timestamp, []],
        ];

        $target->collect($messages, true);

        $regularMessages = array_filter($capturedEvents, static fn(Event $e) => $e->getMessage() === 'regular error');
        $this->assertCount(1, $regularMessages);
    }

    public function testGetLogLevelForProfileBegin(): void
    {
        $target = new SentryTarget(['dsn' => 'https://key@sentry.io/1']);

        $refClass = new ReflectionClass($target);
        $method = $refClass->getMethod('getLogLevel');
        $result = $method->invoke($target, Logger::LEVEL_PROFILE_BEGIN);

        $this->assertEquals(Severity::debug(), $result);
    }

    public function testGetLogLevelForProfileEnd(): void
    {
        $target = new SentryTarget(['dsn' => 'https://key@sentry.io/1']);

        $refClass = new ReflectionClass($target);
        $method = $refClass->getMethod('getLogLevel');
        $result = $method->invoke($target, Logger::LEVEL_PROFILE_END);

        $this->assertEquals(Severity::debug(), $result);
    }

    public function testGetLogLevelForProfile(): void
    {
        $target = new SentryTarget(['dsn' => 'https://key@sentry.io/1']);

        $refClass = new ReflectionClass($target);
        $method = $refClass->getMethod('getLogLevel');
        $result = $method->invoke($target, Logger::LEVEL_PROFILE);

        $this->assertEquals(Severity::debug(), $result);
    }

    public function testGetLogLevelForTrace(): void
    {
        $target = new SentryTarget(['dsn' => 'https://key@sentry.io/1']);

        $refClass = new ReflectionClass($target);
        $method = $refClass->getMethod('getLogLevel');
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

    public function testExportWithFalsyTagValue(): void
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
