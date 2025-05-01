<?php

declare(strict_types=1);

namespace Vanta\Integration\Temporal\Sentry\Test;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertEquals;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use Sentry\ClientInterface as SentryClient;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\EventId;
use Sentry\Integration\IntegrationInterface;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\Serializer\RepresentationSerializer;
use Sentry\Severity;
use Sentry\StacktraceBuilder;
use Sentry\State\Scope;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Spiral\Attributes\AttributeReader;
use Temporal\Activity;
use Temporal\Activity\ActivityInfo;
use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\EncodedValues;
use Temporal\DataConverter\ValuesInterface;
use Temporal\Interceptor\ActivityInbound\ActivityInput;
use Temporal\Interceptor\Header;
use Temporal\Internal\Activity\ActivityContext;
use Temporal\Internal\Marshaller\Mapper\AttributeMapperFactory;
use Temporal\Internal\Marshaller\Marshaller;
use Temporal\Worker\Transport\Goridge;
use Throwable;
use Vanta\Integration\Temporal\Sentry\SentryDoctrineOpenTransactionInterceptor;

#[CoversClass(SentryDoctrineOpenTransactionInterceptor::class)]
final class SentryDoctrineOpenTransactionInterceptorTest extends TestCase
{
    /**
     * @throws Exception
     * @throws ReflectionException
     */
    public function testCaptureOpenTransaction(): void
    {
        $client = new class() implements SentryClient {
            private readonly Options $options;
            private readonly StacktraceBuilder $stacktraceBuilder;

            public function __construct()
            {
                $this->options           = new Options(['dsn' => 'https://1a36864711324ed8a04ba0fa2c89ac5a@sentry.temporal.local/52']);
                $this->stacktraceBuilder = new StacktraceBuilder($this->options, new RepresentationSerializer($this->options));
            }

            public function getOptions(): Options
            {
                return $this->options;
            }

            public function getCspReportUrl(): ?string
            {
                return null;
            }

            public function captureMessage(string $message, ?Severity $level = null, ?Scope $scope = null, ?EventHint $hint = null): ?EventId
            {
                return null;
            }

            public function captureException(Throwable $exception, ?Scope $scope = null, ?EventHint $hint = null): ?EventId
            {
                return null;
            }

            public function captureLastError(?Scope $scope = null, ?EventHint $hint = null): ?EventId
            {
                return null;
            }

            public function captureEvent(Event $event, ?EventHint $hint = null, ?Scope $scope = null): ?EventId
            {
                $extra   = $event->getExtra();
                $context = $event->getContexts();

                assertEquals('A activity opened a transaction but did not close it.', $event->getMessage());
                assertEquals(Severity::error(), $event->getLevel());

                assertArrayHasKey('Args', $extra);
                assertArrayHasKey('Headers', $extra);

                assertEquals([true, ['test' => 'test']], $extra['Args']);

                assertArrayHasKey('Workflow', $context);
                assertArrayHasKey('Id', $context['Workflow']);
                assertEquals('f06e87b1-5e56-4c5d-a789-3f68a7a3af14', $context['Workflow']['Id']);

                assertArrayHasKey('Type', $context['Workflow']);
                assertEquals('Test', $context['Workflow']['Type']);


                assertArrayHasKey('Activity', $context);

                assertArrayHasKey('Id', $context['Activity']);
                assertEquals('92dbc19f-2206-4229-85b7-2ca5cb6ada4a', $context['Activity']['Id']);

                assertArrayHasKey('Type', $context['Activity']);
                assertEquals('Test', $context['Activity']['Type']);

                assertArrayHasKey('TaskQueue', $context['Activity']);
                assertEquals('Test', $context['Activity']['TaskQueue']);


                return null;
            }

            public function getIntegration(string $className): ?IntegrationInterface
            {
                return null;
            }

            public function flush(?int $timeout = null): Result
            {
                return new Result(ResultStatus::skipped());
            }

            public function getStacktraceBuilder(): StacktraceBuilder
            {
                return $this->stacktraceBuilder;
            }
        };


        $connection = new class() extends Connection {
            private int $txLevel;

            public function __construct()
            {
                $this->txLevel = 0;
            }


            public function beginTransaction(): void
            {
                $this->txLevel++;
            }

            public function getTransactionNestingLevel(): int
            {
                return $this->txLevel;
            }
        };



        $hub = SentrySdk::init();

        $hub->bindClient($client);


        $activityInfo = new ActivityInfo();
        $marshaller   = new Marshaller(new AttributeMapperFactory(new AttributeReader()));
        $activityInfo = $marshaller->unmarshal([
            'ActivityID'        => '92dbc19f-2206-4229-85b7-2ca5cb6ada4a',
            'ActivityType'      => ['Name' => 'Test'],
            'TaskQueue'         => 'Test',
            'WorkflowNamespace' => 'Test',
            'WorkflowType'      => ['Name' => 'Test'],
            'WorkflowExecution' => ['ID' => 'f06e87b1-5e56-4c5d-a789-3f68a7a3af14', 'RunID' => '236a53db-3310-4e11-bd04-c13da5cf8f9d'],
        ], $activityInfo);



        Activity::setCurrentContext(
            new class(
                new ActivityContext(
                    Goridge::create(),
                    DataConverter::createDefault(),
                    EncodedValues::fromValues([true, ['test' => 'test']]),
                    Header::empty()
                ),
                $activityInfo
            ) implements Activity\ActivityContextInterface {
                public function __construct(
                    private readonly ActivityContext $context,
                    private readonly ActivityInfo $info,
                ) {
                }


                public function getInfo(): ActivityInfo
                {
                    return $this->info;
                }

                public function getInput(): ValuesInterface
                {
                    return $this->context->getInput();
                }

                public function hasHeartbeatDetails(): bool
                {
                    return $this->context->hasHeartbeatDetails();
                }

                public function getHeartbeatDetails($type = null)
                {
                    return $this->context->getHeartbeatDetails($type);
                }

                public function doNotCompleteOnReturn(): void
                {
                    $this->context->doNotCompleteOnReturn();
                }

                public function heartbeat($details): void
                {
                    $this->context->heartbeat($details);
                }
            },
        );

        $interceptor = new SentryDoctrineOpenTransactionInterceptor($hub, $connection);
        $input       = new ActivityInput(EncodedValues::empty(), Header::empty());
        $handler     = static function (ActivityInput $input) use ($connection): void {
            $connection->beginTransaction();
        };


        $interceptor->handleActivityInbound($input, $handler);
    }
}
