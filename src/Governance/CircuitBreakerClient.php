<?php

declare(strict_types=1);

namespace FastD\Integrations\Governance;

use FastD\Integrations\Exception\CircuitOpenException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class CircuitBreakerClient implements ClientInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly CircuitBreaker $breaker,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (!$this->breaker->allow()) {
            throw new CircuitOpenException('Circuit breaker is open.');
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (Throwable $throwable) {
            $this->breaker->failure();

            throw $throwable;
        }

        if ($response->getStatusCode() >= 500) {
            $this->breaker->failure();
        } else {
            $this->breaker->success();
        }

        return $response;
    }
}
