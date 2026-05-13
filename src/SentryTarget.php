<?php

declare(strict_types=1);

namespace yii2\extensions\sentry;

use Closure;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Integration\ErrorListenerIntegration;
use Sentry\Integration\ExceptionListenerIntegration;
use Sentry\Integration\FatalErrorListenerIntegration;
use Sentry\Integration\IntegrationInterface;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\State\Scope;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\TransactionSource;
use Throwable;
use Yii;
use yii\base\Application;
use yii\helpers\ArrayHelper;
use yii\log\Logger;
use yii\log\Target;
use yii\web\Request;
use yii\web\User;

/**
 * SentryTarget records log messages in a Sentry.
 *
 * @see https://sentry.io
 */
class SentryTarget extends Target
{
    /**
     * @var string Sentry client key.
     */
    public string $dsn;
    /**
     * @var array Options of the \Sentry.
     */
    public array $clientOptions = [];
    /**
     * @var bool Write the context information. The default implementation will dump user information, system variables, etc.
     */
    public bool $context = true;
    /**
     * @var Closure|null Callback function that can modify extra's array
     */
    public ?Closure $extraCallback = null;
    /**
     * @var bool Enable tracing support for Yii2 profiling. When enabled, profile messages
     * are converted to Sentry transactions and spans. Requires `traces_sample_rate` in
     * `clientOptions`, e.g. `['traces_sample_rate' => 1.0]`.
     */
    public bool $tracing = false;

    private ?Transaction $transaction = null;

    /**
     * @inheritDoc
     */
    public function __construct($config = [])
    {
        parent::__construct($config);

        $userOptions = array_merge(['dsn' => $this->dsn], $this->clientOptions);
        $builder = ClientBuilder::create($userOptions);

        $options = $builder->getOptions();
        $options->setIntegrations(static function (array $integrations) {
            // Remove the default error and fatal exception listeners to let us handle those
            return array_filter($integrations, static function (IntegrationInterface $integration): bool {
                if ($integration instanceof ErrorListenerIntegration) {
                    return false;
                }
                if ($integration instanceof ExceptionListenerIntegration) {
                    return false;
                }
                if ($integration instanceof FatalErrorListenerIntegration) {
                    return false;
                }

                return true;
            });
        });

        SentrySdk::init()->bindClient($builder->getClient());

        if ($this->tracing) {
            Yii::$app->on(Application::EVENT_BEFORE_REQUEST, function () {
                $this->startTransaction();
            });
        }
    }

    private function startTransaction(): void
    {
        $client = SentrySdk::getCurrentHub()->getClient();
        if ($client === null || !$client->getOptions()->isTracingEnabled()) {
            return;
        }

        $transactionContext = TransactionContext::make()
            ->setName($this->resolveTransactionName())
            ->setOp($this->resolveTransactionOp())
            ->setSource(TransactionSource::url())
            ->setStartTimestamp(YII_BEGIN_TIME);

        $this->transaction = \Sentry\startTransaction($transactionContext);

        if ($this->transaction->getSampled()) {
            SentrySdk::getCurrentHub()->setSpan($this->transaction);
        }
    }

    /**
     * @inheritdoc
     */
    protected function getContextMessage(): string
    {
        return '';
    }

    /**
     * Returns the configured log levels, automatically including profile levels when tracing is enabled.
     *
     * @return int The log levels bitmask
     */
    public function getLevels(): int
    {
        $levels = parent::getLevels();

        if ($this->tracing) {
            $levels |= Logger::LEVEL_PROFILE;
        }

        return $levels;
    }

    /**
     * @inheritdoc
     */
    public function export(): void
    {
        $profileMessages = [];
        $regularMessages = [];

        foreach ($this->messages as $message) {
            $level = $message[1];
            if ($this->tracing && ($level & Logger::LEVEL_PROFILE)) {
                $profileMessages[] = $message;
            } else {
                $regularMessages[] = $message;
            }
        }

        if (!empty($profileMessages)) {
            $this->processTracingMessages($profileMessages);
        }

        foreach ($regularMessages as $message) {
            [$text, $level, $category] = $message;

            $data = [
                'message' => '',
                'tags' => ['category' => $category],
                'extra' => [],
                'userData' => [],
            ];

            $request = Yii::$app->getRequest();
            if ($request instanceof Request && $request->getUserIP()) {
                $data['userData']['ip_address'] = $request->getUserIP();
            }

            try {
                /** @var ?User $user */
                $user = Yii::$app->has('user', true)
                    ? Yii::$app->get('user', false)
                    : null;

                if (null !== $user && ($identity = $user->getIdentity(false))) {
                    $data['userData']['id'] = $identity->getId();
                }
            } catch (Throwable $e) {
                Yii::error($e);
            }

            \Sentry\withScope(function (Scope $scope) use ($text, $level, $data) {
                if (is_array($text)) {
                    if (isset($text['msg'])) {
                        $data['message'] = (string)$text['msg'];
                        unset($text['msg']);
                    }
                    if (isset($text['message'])) {
                        $data['message'] = (string)$text['message'];
                        unset($text['message']);
                    }

                    if (isset($text['tags'])) {
                        $data['tags'] = ArrayHelper::merge($data['tags'], $text['tags']);
                        unset($text['tags']);
                    }

                    if (isset($text['exception']) && $text['exception'] instanceof Throwable) {
                        $data['exception'] = $text['exception'];
                        unset($text['exception']);
                    }

                    $data['extra'] = $text;
                } else {
                    $data['message'] = (string) $text;
                }

                if ($this->context) {
                    $data['extra']['context'] = parent::getContextMessage();
                }

                $data = $this->runExtraCallback($text, $data);

                $scope->setUser($data['userData']);
                foreach ($data['extra'] as $key => $value) {
                    $scope->setExtra((string) $key, $value);
                }
                foreach ($data['tags'] as $key => $value) {
                    if ($value) {
                        $scope->setTag($key, $value);
                    }
                }

                if ($text instanceof Throwable) {
                    \Sentry\captureException($text);
                } else {
                    $event = Event::createEvent();
                    $event->setMessage($data['message']);
                    $event->setLevel($this->getLogLevel($level));

                    \Sentry\captureEvent($event, EventHint::fromArray(array_filter([
                        'exception' => $data['exception'] ?? null,
                    ])));
                }
            });
        }
    }

    /**
     * Processes Yii2 profiling messages and converts them to a single Sentry transaction
     * with child spans for each profile block.
     *
     * A single Transaction is created per request, representing the full operation.
     * Each `beginProfile`/`endProfile` pair becomes a child Span within that Transaction.
     *
     * @param array<int, array{0: mixed, 1: int, 2: string, 3: float, 4?: array<int, mixed>}> $messages Profile log messages to process
     */
    protected function processTracingMessages(array $messages): void
    {
        $client = SentrySdk::getCurrentHub()->getClient();
        if ($client === null || !$client->getOptions()->isTracingEnabled()) {
            return;
        }

        if ($this->transaction === null) {
            $this->startTransaction();
        }

        $transaction = $this->transaction;

        if ($transaction === null || !$transaction->getSampled()) {
            return;
        }

        $previousSpan = SentrySdk::getCurrentHub()->getSpan();
        SentrySdk::getCurrentHub()->setSpan($transaction);

        $stack = [];

        foreach ($messages as $message) {
            [$text, $level, $category, $timestamp] = $message;
            $hash = md5((string) json_encode($text, JSON_THROW_ON_ERROR));

            if ($level === Logger::LEVEL_PROFILE_BEGIN) {
                $parent = empty($stack) ? $transaction : $stack[count($stack) - 1]['span'];

                $context = SpanContext::make()
                    ->setOp($category)
                    ->setDescription((string) $text)
                    ->setStartTimestamp($timestamp);

                $span = $parent->startChild($context);
                $stack[] = ['hash' => $hash, 'span' => $span];
            } elseif ($level === Logger::LEVEL_PROFILE_END) {
                for ($i = count($stack) - 1; $i >= 0; $i--) {
                    if ($stack[$i]['hash'] === $hash) {
                        $stack[$i]['span']->finish($timestamp);
                        array_splice($stack, $i, 1);
                        break;
                    }
                }
            }
        }

        $transaction->finish();
        SentrySdk::getCurrentHub()->setSpan($previousSpan);
        $this->transaction = null;
    }

    /**
     * Resolves the transaction name based on the application type.
     *
     * For web applications, returns the HTTP method and URL.
     * For console applications, returns the CLI command route.
     *
     * @return string The transaction name
     */
    protected function resolveTransactionName(): string
    {
        try {
            $request = Yii::$app->getRequest();
            if ($request instanceof Request) {
                return $request->getMethod() . ' ' . $request->getPathInfo();
            }

            return 'cli ' . (Yii::$app->requestedRoute ?: 'unknown');
        } catch (Throwable) {
            return 'yii2-request';
        }
    }

    /**
     * Resolves the transaction operation based on the application type.
     *
     * @return string The transaction operation identifier
     */
    protected function resolveTransactionOp(): string
    {
        try {
            return Yii::$app->getRequest() instanceof Request ? 'http.server' : 'cli.server';
        } catch (Throwable) {
            return 'http.server';
        }
    }

    /**
     * Calls the extra callback if it exists
     *
     * @param mixed $text
     * @param array $data
     *
     * @return array
     */
    public function runExtraCallback(mixed $text, array $data): array
    {
        if (is_callable($this->extraCallback)) {
            $data['extra'] = call_user_func($this->extraCallback, $text, $data['extra'] ?? []);
        }

        return $data;
    }

    /**
     * Returns the text display of the specified level for the Sentry.
     *
     * @param int $level The message level, e.g. [[LEVEL_ERROR]], [[LEVEL_WARNING]].
     *
     * @return string
     */
    public static function getLevelName(int $level): string
    {
        static $levels = [
            Logger::LEVEL_ERROR => 'error',
            Logger::LEVEL_WARNING => 'warning',
            Logger::LEVEL_INFO => 'info',
            Logger::LEVEL_TRACE => 'debug',
            Logger::LEVEL_PROFILE_BEGIN => 'debug',
            Logger::LEVEL_PROFILE_END => 'debug',
        ];

        return $levels[$level] ?? 'error';
    }

    /**
     * Translates Yii2 log levels to Sentry Severity.
     *
     * @param int $level
     *
     * @return Severity
     */
    protected function getLogLevel(int $level): Severity
    {
        return match ($level) {
            Logger::LEVEL_PROFILE, Logger::LEVEL_PROFILE_BEGIN, Logger::LEVEL_PROFILE_END, Logger::LEVEL_TRACE => Severity::debug(),
            Logger::LEVEL_WARNING => Severity::warning(),
            Logger::LEVEL_ERROR => Severity::error(),
            default => Severity::info(),
        };
    }
}
