<?php

declare(strict_types=1);

namespace Vanta\Integration\Temporal\Sentry\Test;

use Closure;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertIsBool;
use function PHPUnit\Framework\assertTrue;

use PHPUnit\Framework\Attributes\CoversClass;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface as Promise;

use function React\Promise\resolve;

use ReflectionException;

use RuntimeException;
use Sentry\ClientInterface as SentryClient;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\EventId;

use function Sentry\init;

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
use stdClass;
use Temporal\DataConverter\EncodedValues;
use Temporal\Exception\ExceptionInterceptor;
use Temporal\Interceptor\SimplePipelineProvider;
use Temporal\Interceptor\WorkflowOutboundCalls\CompleteInput;
use Temporal\Interceptor\WorkflowOutboundCalls\PanicInput;
use Temporal\Internal\Declaration\Destroyable;
use Temporal\Internal\Declaration\WorkflowInstanceInterface as WorkflowInstance;
use Temporal\Internal\Marshaller\Mapper\AttributeMapperFactory;
use Temporal\Internal\Marshaller\Marshaller;
use Temporal\Internal\Queue\ArrayQueue;
use Temporal\Internal\ServiceContainer;
use Temporal\Internal\Transport\Client;
use Temporal\Internal\Workflow\Input;
use Temporal\Internal\Workflow\WorkflowContext;
use Temporal\WorkerFactory;
use Temporal\Workflow;
use Throwable;
use Vanta\Integration\Temporal\Sentry\SentryWorkflowOutboundCallsInterceptor;

#[CoversClass(SentryWorkflowOutboundCallsInterceptor::class)]
final class SentryWorkflowOutboundCallsInterceptorTest extends TestCase
{
    /**
     * @param callable(SentryWorkflowOutboundCallsInterceptor,callable(object): Promise): Promise $handler
     */
    #[DataProvider('successHandleDataProvider')]
    public function testSuccessHandle(callable $handler): void
    {
        init(['dsn' => 'https://1a36864711324ed8a04ba0fa2c89ac5a@sentry.temporal.local/52']);


        Workflow::setCurrentContext(
            new WorkflowContext(
                ServiceContainer::fromWorkerFactory(WorkerFactory::create(), ExceptionInterceptor::createDefault(), new SimplePipelineProvider([])),
                new Client(new ArrayQueue()),
                new NullWorkflowInstance(),
                new Input()
            )
        );


        $hub         = SentrySdk::getCurrentHub();
        $client      = $hub->getClient() ?? throw new RuntimeException('Not Found client');
        $interceptor = new SentryWorkflowOutboundCallsInterceptor($hub, $client->getStacktraceBuilder());
        $promise     = $handler($interceptor, static fn (object $input): Promise => resolve(false));

        $promise->then(static function (mixed $v): void {
            assertIsBool($v);
            assertTrue($v);
        });
    }

    /**
     * @return iterable<callable(SentryWorkflowOutboundCallsInterceptor, callable(object): Promise): Promise>
     */
    public static function successHandleDataProvider(): iterable
    {
        yield [static fn (SentryWorkflowOutboundCallsInterceptor $i, callable $n): Promise => $i->panic(new PanicInput(null), $n)];
        yield [static fn (SentryWorkflowOutboundCallsInterceptor $i, callable $n): Promise => $i->complete(new CompleteInput(null, null), $n)];
    }


    /**
     * @param callable(SentryWorkflowOutboundCallsInterceptor, Throwable ,callable(object): Promise): Promise $handler
     *
     * @throws ReflectionException
     */
    #[DataProvider('handleThrowableDataProvider')]
    public function testHandleThrowable(callable $handler): void
    {
        $throwable = new RuntimeException('Oops!');

        $workflowInfo = new Workflow\WorkflowInfo();
        $marshaller   = new Marshaller(new AttributeMapperFactory(new AttributeReader()));
        $workflowInfo = $marshaller->unmarshal([
            'TaskQueueName'     => 'Test',
            'Namespace'         => 'Test',
            'WorkflowType'      => ['Name' => 'Test'],
            'WorkflowExecution' => ['ID' => 'f06e87b1-5e56-4c5d-a789-3f68a7a3af14', 'RunID' => '236a53db-3310-4e11-bd04-c13da5cf8f9d'],
        ], $workflowInfo);

        Workflow::setCurrentContext(
            new WorkflowContext(
                ServiceContainer::fromWorkerFactory(WorkerFactory::create(), ExceptionInterceptor::createDefault(), new SimplePipelineProvider([])),
                new Client(new ArrayQueue()),
                new NullWorkflowInstance(),
                new Input($workflowInfo, EncodedValues::fromValues([true, ['test' => 'test']]))
            )
        );


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

                assertArrayHasKey('Args', $extra);
                assertEquals([true, ['test' => 'test']], $extra['Args']);

                assertArrayHasKey('Workflow', $context);

                assertArrayHasKey('Id', $context['Workflow']);
                assertEquals('f06e87b1-5e56-4c5d-a789-3f68a7a3af14', $context['Workflow']['Id']);

                assertArrayHasKey('Type', $context['Workflow']);
                assertEquals('Test', $context['Workflow']['Type']);

                assertArrayHasKey('TaskQueue', $context['Workflow']);
                assertEquals('Test', $context['Workflow']['TaskQueue']);

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


        $hub = SentrySdk::init();
        $hub->bindClient($client);

        $interceptor = new SentryWorkflowOutboundCallsInterceptor($hub, $client->getStacktraceBuilder());

        $handler($interceptor, $throwable, static fn (object $input): Promise => resolve(true));
    }



    /**
     * @return iterable<callable(SentryWorkflowOutboundCallsInterceptor, callable(object): Promise): Promise>
     */
    public static function handleThrowableDataProvider(): iterable
    {
        yield [static fn (SentryWorkflowOutboundCallsInterceptor $i, Throwable $t, callable $n): Promise => $i->panic(new PanicInput($t), $n)];
        yield [static fn (SentryWorkflowOutboundCallsInterceptor $i, Throwable $t, callable $n): Promise => $i->complete(new CompleteInput(null, $t), $n)];
    }
}


final class NullWorkflowInstance implements WorkflowInstance, Destroyable
{
    public function getHandler(): callable
    {
        return static fn (): bool => false;
    }

    public function getContext(): ?object
    {
        return new stdClass();
    }

    public function initConstructor(): void
    {
        // TODO: Implement initConstructor() method.
    }

    public function findQueryHandler(string $name): ?Closure
    {
        return static fn (): bool => false;
    }

    public function addQueryHandler(string $name, callable $handler): void
    {
        // TODO: Implement addQueryHandler() method.
    }

    public function getSignalHandler(string $name): Closure
    {
        return static fn (): bool => false;
    }

    public function addSignalHandler(string $name, callable $handler): void
    {
        // TODO: Implement addSignalHandler() method.
    }

    public function clearSignalQueue(): void
    {
        // TODO: Implement clearSignalQueue() method.
    }

    public function destroy(): void
    {
        // TODO: Implement destroy() method.
    }
}
