# Temporal Sentry

[Temporal](https://temporal.io/) is the simple, scalable open source way to write and run reliable cloud applications.


## Introduction

Sentry SDK for [`temporalio/sdk-php`](https://github.com/temporalio/sdk-php)



## Installation


```bash
composer require vanta/temporal-sentry
```



## Usage


```php
<?php

declare(strict_types=1);

use Sentry\SentrySdk;
use Temporal\Interceptor\SimplePipelineProvider;
use Temporal\WorkerFactory;
use Vanta\Integration\Temporal\Sentry\SentryActivityInboundInterceptor;
use Vanta\Integration\Temporal\Sentry\SentryWorkflowOutboundCallsInterceptor;
use function Sentry\init;

require_once __DIR__ . '/vendor/autoload.php';

init(['dsn' => 'https://1a36864711324ed8a04ba0fa2c89ac5a@sentry.temporal.local/52']);

$hub     = SentrySdk::getCurrentHub();
$client  = $hub->getClient() ?? throw new \RuntimeException('Not Found client');
$factory = WorkerFactory::create();

$worker = $factory->newWorker(
    interceptorProvider: new SimplePipelineProvider([
        new SentryActivityInboundInterceptor($hub, $client->getStacktraceBuilder()),
        new SentryWorkflowOutboundCallsInterceptor($hub, $client->getStacktraceBuilder()),
    ])
);
```