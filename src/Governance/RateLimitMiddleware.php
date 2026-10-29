<?php

declare(strict_types=1);

namespace FastD\Integrations\Governance;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RateLimitMiddleware implements MiddlewareInterface
{
    /** @var \Closure(ServerRequestInterface): string */
    private readonly \Closure $keyResolver;

    public function __construct(
        private readonly RateLimiterInterface $limiter,
        private readonly ResponseFactoryInterface $responseFactory,
        ?\Closure $keyResolver = null,
    ) {
        $this->keyResolver = $keyResolver ?? static function (ServerRequestInterface $request): string {
            return $request->getHeaderLine('X-Api-Key')
                ?: $request->getHeaderLine('X-Forwarded-For')
                ?: 'anonymous';
        };
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $key = ($this->keyResolver)($request);

        if (!$this->limiter->attempt($key)) {
            return $this->responseFactory->createResponse(429)
                ->withHeader('Retry-After', '1');
        }

        return $handler->handle($request);
    }
}
