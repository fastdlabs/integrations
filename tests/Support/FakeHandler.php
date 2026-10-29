<?php

declare(strict_types=1);

namespace FastD\Integrations\Tests\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class FakeHandler implements RequestHandlerInterface
{
    /** @var ServerRequestInterface[] */
    public array $requests = [];

    public function __construct(private readonly ResponseInterface $response)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return $this->response;
    }
}
