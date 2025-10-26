<?php

/**
 * Temporal Sentry
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2024, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Temporal\Sentry;

use function iterator_to_array;

use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionDataBag;
use Sentry\StacktraceBuilder;
use Sentry\State\HubInterface as Hub;
use Temporal\Activity;
use Temporal\Interceptor\ActivityInbound\ActivityInput;
use Temporal\Interceptor\ActivityInboundInterceptor;
use Throwable;

final class SentryActivityInboundInterceptor implements ActivityInboundInterceptor
{
    public function __construct(
        private readonly Hub $hub,
        private readonly StacktraceBuilder $stacktraceBuilder,
    ) {
    }


    /**
     * @throws Throwable
     */
    public function handleActivityInbound(ActivityInput $input, callable $next): mixed
    {
        $this->hub->pushScope();

        try {
            return $next($input);
        } catch (Throwable $e) {
            $stackTrace = $this->stacktraceBuilder->buildFromException($e);

            $event = Event::createEvent();
            $event->setExceptions([new ExceptionDataBag($e, $stackTrace)]);
            $event->setContext('Activity', [
                'Id'        => Activity::getInfo()->id,
                'Type'      => Activity::getInfo()->type->name,
                'TaskQueue' => Activity::getInfo()->taskQueue,
            ]);
            $event->setContext('Workflow', [
                'Namespace' => Activity::getInfo()->workflowNamespace,
                'Type'      => Activity::getInfo()->workflowType?->name,
                'Id'        => Activity::getInfo()->workflowExecution?->getID(),
            ]);

            $request = [];

            foreach (Activity::getInput()->getValues() as $value) {
                $request[] = $value;
            }

            $event->setExtra([
                'Args'    => $request,
                'Headers' => iterator_to_array($input->header->getIterator()),
            ]);

            $eventHit = EventHint::fromArray(['exception' => $e]);

            $this->hub->captureEvent($event, $eventHit);

            throw $e;
        } finally {
            $this->hub->popScope();
        }
    }
}
