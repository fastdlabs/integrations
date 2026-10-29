<?php

declare(strict_types=1);

namespace FastD\Integrations\Tracing;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 提取或生成 W3C traceparent，并把上下文放入请求属性。
 *
 * 只负责上下文传播；Span 采集/导出由 OpenTelemetry 等外部实现负责。
 */
final class TracingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly bool $trustIncoming = true,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $incoming = $this->trustIncoming
            ? $request->getHeaderLine('traceparent')
            : null;

        $context = TraceContext::fromTraceparent($incoming !== '' ? $incoming : null);

        $response = $handler->handle(
            $request->withAttribute(TraceContext::ATTRIBUTE, $context),
        );

        return $response->withHeader('X-Trace-Id', $context->traceId);
    }
}
