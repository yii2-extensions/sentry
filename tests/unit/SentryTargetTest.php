<?php

declare(strict_types=1);

namespace yii2\extensions\sentry\tests\unit;

use Codeception\Test\Unit;
use Generator;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Sentry\Client;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\EventId;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use yii\log\Logger;
use yii2\extensions\sentry\SentryTarget;

/**
 * Unit-tests for SentryTarget
 */
class SentryTargetTest extends Unit
{
    /** @var array test messages */
    protected array $messages = [
        ['test', Logger::LEVEL_INFO, 'test', 1481513561.197593, []],
        ['test 2', Logger::LEVEL_INFO, 'test 2', 1481513572.867054, []]
    ];

    /**
     * Testing method getContextMessage()
     * - returns empty string ''
     * @see SentryTarget::getContextMessage
     */
    public function testGetContextMessage(): void
    {
        $class = new ReflectionClass(SentryTarget::class);
        $method = $class->getMethod('getContextMessage');

        $sentryTarget = new SentryTarget();
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
            ->willReturnCallback(function (Event $event, ?EventHint $hint = null, ?Scope $scope = null) use ($logData, &$messageWasSent): ?EventId {
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

    /**
     * @dataProvider messageDataProvider
     */
    public function testMessageConverting($expectedMessageText, $loggedMessage): void
    {
        $sentryTarget = $this->getConfiguredSentryTarget();
        $messageWasSent = false;

        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
               ->method('captureEvent')
               ->willReturnCallback(function (Event $event, ?EventHint $hint = null, ?Scope $scope = null) use ($expectedMessageText, &$messageWasSent): ?EventId {
                   $messageWasSent = true;
                   $this->assertSame($expectedMessageText, $event->getMessage());

                   return EventId::generate();
               });

        SentrySdk::getCurrentHub()->bindClient($client);

        $sentryTarget->collect([[$loggedMessage, Logger::LEVEL_INFO, 'application', 1481513561.197593, []]], true);
        $this->assertTrue($messageWasSent);
    }

    /**
     * Testing method getLevelName()
     * - returns level name for each logger level
     * @see SentryTarget::getLevelName
     */
    public function testGetLevelName(): void
    {
        //valid level names
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
                $level = SentryTarget::getLevelName($value);
                $this->assertNotEmpty($level);
                $this->assertContains($level, $levelNames, sprintf('Level "%s" is incorrect', $level));
            }
        }

        //check default level name
        $this->assertEquals('error', SentryTarget::getLevelName(''));
        $this->assertEquals('error', SentryTarget::getLevelName('somerandomstring' . uniqid()));
    }

    /**
     * Testing method collect()
     * - assigns messages to Target property
     * - creates Sentry object
     * @see SentryTarget::collect
     */
    public function testCollect(): void
    {
        $sentryTarget = $this->getConfiguredSentryTarget();

        $sentryTarget->collect($this->messages, false);
        $this->assertSameSize($this->messages, $sentryTarget->messages);
    }

    /**
     * Testing method export()
     * - Sentry::capture is called on collect([...], true)
     * - messages stack is cleaned on  collect([...], true)
     * - Sentry::capture is called on export()
     * @see SentryTarget::export
     */
    public function testExport(): void
    {
        $sentryTarget = $this->getConfiguredSentryTarget();

        //test calling client and clearing messages on final collect
        $sentryTarget->collect($this->messages, true);
        $this->assertEmpty($sentryTarget->messages);

        //add messages and test simple export() method
        $sentryTarget->collect($this->messages, false);
        $sentryTarget->export();
        $this->assertSameSize($this->messages, $sentryTarget->messages);
    }

    /**
     * Returns configured SentryTarget object
     *
     * @return SentryTarget
     * @throws \yii\base\InvalidConfigException
     */
    protected function getConfiguredSentryTarget(): SentryTarget
    {
        $sentryTarget = new SentryTarget();
        $sentryTarget->exportInterval = 100;
        $sentryTarget->setLevels(Logger::LEVEL_INFO);

        return $sentryTarget;
    }

    /**
     * Returns reflected 'client' property
     *
     * @param SentryTarget $sentryTarget
     * @return ReflectionProperty
     */
    protected function getAccessibleClientProperty(SentryTarget $sentryTarget): ReflectionProperty
    {
        return new ReflectionClass(Client::class)->getProperty('transport');
    }

    /**
     * Compatible version of creating mock method
     *
     * @param string $className
     */
    protected function getMockCompatible(string $className)
    {
        return method_exists($this, 'createMock') ?
            $this->createMock($className) : $this->getMock($className);
    }
}
