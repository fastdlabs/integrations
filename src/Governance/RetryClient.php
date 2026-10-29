<?php

declare(strict_types=1);

namespace FastD\Integrations\Governance;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class RetryClient implements ClientInterface
{
    /** @var \Closure(int): void */
    private readonly \Closure $sleeper;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly RetryPolicy $policy,
        ?\Closure $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $attempt = 0;

        while (true) {
            $attempt++;
            $response = null;
            $error = null;

            try {
                $response = $this->client->sendRequest($request);
            } catch (Throwable $throwable) {
                $error = $throwable;
            }

            if (!$this->policy->shouldRetry($request, $response, $error, $attempt)) {
                if ($error !== null) {
                    throw $error;
                }

                return $response;
            }

            ($this->sleeper)($this->policy->delayMs($attempt));
        }
    }
}
