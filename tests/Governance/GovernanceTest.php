<?php

declare(strict_types=1);

namespace FastD\Integrations\Tests\Governance;

use FastD\Http\Factory;
use FastD\Http\Response\Json;
use FastD\Integrations\Exception\CircuitOpenException;
use FastD\Integrations\Governance\CircuitBreaker;
use FastD\Integrations\Governance\CircuitBreakerClient;
use FastD\Integrations\Governance\InMemoryRateLimiter;
use FastD\Integrations\Governance\RateLimitMiddleware;
use FastD\Integrations\Governance\RetryClient;
use FastD\Integrations\Governance\RetryPolicy;
use FastD\Integrations\Tests\Support\FakeClient;
use FastD\Integrations\Tests\Support\FakeHandler;
use PHPUnit\Framework\TestCase;

final class GovernanceTest extends TestCase
{
    public function testRetryClientRetriesRetryableStatusAndUsesBackoff(): void
    {
        $factory = new Factory();
        $http = new FakeClient([
            new Json(503, []),
            new Json(200, ['ok' => true]),
        ]);
        $delays = [];
        $client = new RetryClient(
            $http,
            new RetryPolicy(maxAttempts: 2, baseDelayMs: 25),
            static function (int $delay) use (&$delays): void {
                $delays[] = $delay;
            },
        );

        $response = $client->sendRequest($factory->createRequest('GET', 'http://user/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $http->requests);
        self::assertSame([25], $delays);
    }

    public function testCircuitBreakerOpensAndHalfOpens(): void
    {
        $now = 100;
        $breaker = new CircuitBreaker(
            failureThreshold: 2,
            cooldownSeconds: 30,
            clock: static function () use (&$now): int {
                return $now;
            },
        );

        self::assertTrue($breaker->allow());
        $breaker->failure();
        $breaker->failure();
        self::assertSame(CircuitBreaker::OPEN, $breaker->state());
        self::assertFalse($breaker->allow());

        $now += 31;
        self::assertSame(CircuitBreaker::HALF_OPEN, $breaker->state());
        self::assertTrue($breaker->allow());
        $breaker->success();
        self::assertSame(CircuitBreaker::CLOSED, $breaker->state());
    }

    public function testCircuitBreakerClientRejectsOpenCircuit(): void
    {
        $now = 0;
        $breaker = new CircuitBreaker(1, 60, static function () use (&$now): int {
            return $now;
        });
        $breaker->failure();

        $client = new CircuitBreakerClient(new FakeClient(), $breaker);

        $this->expectException(CircuitOpenException::class);
        $client->sendRequest((new Factory())->createRequest('GET', 'http://user/'));
    }

    public function testInMemoryRateLimiterRefills(): void
    {
        $now = 0;
        $limiter = new InMemoryRateLimiter(2, 1, static function () use (&$now): int {
            return $now;
        });

        self::assertTrue($limiter->attempt('a'));
        self::assertTrue($limiter->attempt('a'));
        self::assertFalse($limiter->attempt('a'));

        $now += 1;
        self::assertTrue($limiter->attempt('a'));
        self::assertTrue($limiter->attempt('b'));
    }

    public function testRateLimitMiddlewareReturns429(): void
    {
        $factory = new Factory();
        $limiter = new InMemoryRateLimiter(1, 1);
        $handler = new FakeHandler(new Json(200, []));
        $middleware = new RateLimitMiddleware($limiter, $factory);

        $request = $factory->createServerRequest('GET', '/');
        self::assertSame(200, $middleware->process($request, $handler)->getStatusCode());

        $response = $middleware->process($request, $handler);
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('1', $response->getHeaderLine('Retry-After'));
    }
}
