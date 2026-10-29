<?php

declare(strict_types=1);

namespace FastD\Integrations\Tracing;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 下游 PSR-18 调用注入 W3C traceparent。
 */
final class TraceClient implements ClientInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly TraceContext $context,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($request->getHeaderLine('traceparent') === '') {
            $request = $request->withHeader('traceparent', $this->context->traceparent());
        }

        return $this->client->sendRequest($request);
    }
}
