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
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\TransactionSource;
use Throwable;
use Yii;
use yii\base\Application;
use yii\helpers\ArrayHelper;
use yii\log\Logger;
use yii\log\Target;
use yii\web\Request;
use yii\web\Response;
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
     * @var array<string, mixed> Options of the \Sentry.
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
     * @var bool Enable Sentry Performance and Profiling.
     * When set to true, `traces_sample_rate` and `profiles_sample_rate` are set to 1
     * as defaults in client options (can be overridden via `clientOptions`).
     * A Sentry Transaction is started on `beforeRequest` and finished on `afterRequest`.
     */
    public bool $tracing = false;

    /**
     * @inheritDoc
     */
    public function __construct($config = [])
    {
        parent::__construct($config);

        if ($this->tracing) {
            $this->clientOptions = ArrayHelper::merge(
                [
                    'traces_sample_rate' => 1,
                    'profiles_sample_rate' => 1,
                ],
                $this->clientOptions,
            );
        }

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
            $this->registerTracingHandlers();
        }
    }

    private function registerTracingHandlers(): void
    {
        Yii::$app->on(Application::EVENT_BEFORE_REQUEST, function () {
            $request = Yii::$app->getRequest();
            $sentryTrace = null;
            $baggage = null;

            if ($request instanceof Request) {
                $sentryTrace = $request->getHeaders()->get('sentry-trace');
                $baggage = $request->getHeaders()->get('baggage');
            }

            if ($sentryTrace !== null) {
                $transactionContext = \Sentry\continueTrace($sentryTrace, $baggage ?? '');
            } else {
                $transactionContext = new TransactionContext();
            }

            $transactionContext->setName($this->getTransactionName());
            $transactionContext->setOp('http.server');

            $transaction = \Sentry\startTransaction($transactionContext);

            SentrySdk::getCurrentHub()->setSpan($transaction);
        });

        Yii::$app->on(Application::EVENT_AFTER_REQUEST, function () {
            $transaction = SentrySdk::getCurrentHub()->getTransaction();

            if ($transaction !== null) {
                $request = Yii::$app->getRequest();

                if ($request instanceof Request) {
                    $name = Yii::$app->requestedRoute ?: $this->getTransactionName();
                    $source = Yii::$app->requestedRoute
                        ? TransactionSource::route()
                        : TransactionSource::url();

                    $transaction->setName($name);
                    $transaction->getMetadata()->setSource($source);

                    /** @var Response $response */
                    $response = Yii::$app->getResponse();
                    $transaction->setHttpStatus($response->getStatusCode());
                }

                $transaction->finish();
                \Sentry\flush();
            }
        });
    }

    private function getTransactionName(): string
    {
        $request = Yii::$app->getRequest();

        if ($request instanceof Request) {
            return $request->getMethod() . ' ' . ($request->getPathInfo() ?: '/');
        }

        return '<unknown>';
    }

    /**
     * @inheritdoc
     */
    protected function getContextMessage(): string
    {
        return '';
    }

    /**
     * @inheritdoc
     */
    public function export(): void
    {
        foreach ($this->messages as $message) {
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
     * Calls the extra callback if it exists
     *
     * @param mixed $text
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
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
