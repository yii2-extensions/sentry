<?php

declare(strict_types=1);

namespace yii2\extensions\sentry\tests;

use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\EventId;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use yii\log\Logger;
use yii2\extensions\sentry\SentryTarget;

class SentryTargetTest extends TestCase
{
    protected array $messages = [
        ['test', Logger::LEVEL_INFO, 'test', 1481513561.197593, []],
        ['test 2', Logger::LEVEL_INFO, 'test 2', 1481513572.867054, []]
    ];

    public function testGetContextMessage(): void
    {
        $class = new ReflectionClass(SentryTarget::class);
        $method = $class->getMethod('getContextMessage');

        $sentryTarget = new SentryTarget(['dsn' => 'https://key@sentry.io/1']);
        $result = $method->invokeArgs($sentryTarget, []);

        $this->assertEmpty($result);
    }

    public function testExceptionPassing(): void
    {
        $sentryTarget = $this->getConfiguredSentryTarget();

        $logData = [
            'message' => 'This exception was caught, but still needs to be reported',
            'exception' => new RuntimeException('Package loss detected'),
            'something_extra' => ['foo' => 'bar'],
        ];

        $messageWasSent = false;

        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
            ->method('captureEvent')
            ->willReturnCallback(function (Event $event, ?EventHint $hint = null, ?Scope $scope = null) use ($logData, &$messageWasSent): EventId {
                $messageWasSent = true;
                $this->assertSame($logData['exception'], $hint?->exception);
                $this->assertSame($logData['message'], $event->getMessage());

                return EventId::generate();
            });

        SentrySdk::getCurrentHub()->bindClient($client);

        $sentryTarget->collect([[$logData, Logger::LEVEL_INFO, 'application', 1481513561.197593, []]], true);
        $this->assertTrue($messageWasSent);
    }

    public static function messageDataProvider(): Generator
    {
        $msg = 'A message';

        yield [$msg, $msg];

        yield [$msg, ['msg' => $msg]];

        yield [$msg, ['message' => $msg]];

        yield [$msg, ['message' => $msg, 'msg' => 'Ignored']];
    }

    #[DataProvider('messageDataProvider')]
    public function testMessageConverting(string $expectedMessageText, string|array $loggedMessage): void
    {
        $sentryTarget = $this->getConfiguredSentryTarget();
        $messageWasSent = false;

        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
               ->method('captureEvent')
               ->willReturnCallback(function (Event $event, ?EventHint $hint = null, ?Scope $scope = null) use ($expectedMessageText, &$messageWasSent): EventId {
                   $messageWasSent = true;
                   $this->assertSame($expectedMessageText, $event->getMessage());

                   return EventId::generate();
               });

        SentrySdk::getCurrentHub()->bindClient($client);

        $sentryTarget->collect([[$loggedMessage, Logger::LEVEL_INFO, 'application', 1481513561.197593, []]], true);
        $this->assertTrue($messageWasSent);
    }

    public function testGetLevelName(): void
    {
        $levelNames = [
            'info',
            'error',
            'warning',
            'debug',
        ];

        $loggerClass = new ReflectionClass(Logger::class);
        $loggerLevelConstants = $loggerClass->getConstants();
        foreach ($loggerLevelConstants as $constant => $value) {
            if (str_starts_with($constant, 'LEVEL_')) {
                $level = SentryTarget::getLevelName((int) $value);
                $this->assertNotEmpty($level);
                $this->assertContains($level, $levelNames, sprintf('Level "%s" is incorrect', $level));
            }
        }

        $this->assertEquals('error', SentryTarget::getLevelName(0));
    }

    public function testCollect(): void
    {
        $sentryTarget = $this->getConfiguredSentryTarget();

        $sentryTarget->collect($this->messages, false);
        $this->assertSameSize($this->messages, $sentryTarget->messages);
    }

    public function testExport(): void
    {
        $sentryTarget = $this->getConfiguredSentryTarget();

        $sentryTarget->collect($this->messages, true);
        $this->assertEmpty($sentryTarget->messages);

        $sentryTarget->collect($this->messages, false);
        $sentryTarget->export();
        $this->assertSameSize($this->messages, $sentryTarget->messages);
    }

    protected function getConfiguredSentryTarget(): SentryTarget
    {
        $sentryTarget = new SentryTarget(['dsn' => 'https://key@sentry.io/1']);
        $sentryTarget->exportInterval = 100;
        $sentryTarget->setLevels(Logger::LEVEL_INFO);

        return $sentryTarget;
    }
}
