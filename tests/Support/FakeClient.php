<?php

declare(strict_types=1);

namespace FastD\Integrations\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class FakeClient implements ClientInterface
{
    /** @var RequestInterface[] */
    public array $requests = [];

    /**
     * @param array<int, ResponseInterface|Throwable> $responses
     */
    public function __construct(private array $responses = [])
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $next = array_shift($this->responses);

        if ($next instanceof Throwable) {
            throw $next;
        }

        if (!$next instanceof ResponseInterface) {
            throw new \RuntimeException('No fake response configured.');
        }

        return $next;
    }
}
