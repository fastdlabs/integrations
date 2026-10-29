<?php

declare(strict_types=1);

namespace FastD\Integrations\Tests\Tracing;

use FastD\Http\Factory;
use FastD\Http\Response\Json;
use FastD\Integrations\Tests\Support\FakeClient;
use FastD\Integrations\Tests\Support\FakeHandler;
use FastD\Integrations\Tracing\TraceClient;
use FastD\Integrations\Tracing\TraceContext;
use FastD\Integrations\Tracing\TracingMiddleware;
use PHPUnit\Framework\TestCase;

final class TracingTest extends TestCase
{
    public function testTraceContextParsesAndChildKeepsTraceId(): void
    {
        $context = TraceContext::fromTraceparent('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $context->traceId);
        self::assertSame('00f067aa0ba902b7', $context->spanId);
        self::assertTrue($context->sampled);

        $child = $context->child();
        self::assertSame($context->traceId, $child->traceId);
        self::assertNotSame($context->spanId, $child->spanId);
        self::assertStringStartsWith('00-', $child->traceparent());
    }

    public function testTracingMiddlewareAddsAttributeAndResponseHeader(): void
    {
        $factory = new Factory();
        $handler = new FakeHandler(new Json(200, []));
        $middleware = new TracingMiddleware();

        $request = $factory->createServerRequest('GET', '/')
            ->withHeader('traceparent', '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');

        $response = $middleware->process($request, $handler);

        self::assertInstanceOf(
            TraceContext::class,
            $handler->requests[0]->getAttribute(TraceContext::ATTRIBUTE),
        );
        self::assertSame(
            '4bf92f3577b34da6a3ce929d0e0e4736',
            $response->getHeaderLine('X-Trace-Id'),
        );
    }

    public function testTraceClientInjectsTraceparent(): void
    {
        $factory = new Factory();
        $http = new FakeClient([new Json(200, [])]);
        $context = TraceContext::fromTraceparent(
            '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        );
        $client = new TraceClient($http, $context);

        $client->sendRequest($factory->createRequest('GET', 'http://user/'));

        self::assertSame(
            '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
            $http->requests[0]->getHeaderLine('traceparent'),
        );
    }
}
