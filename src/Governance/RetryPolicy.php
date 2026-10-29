<?php

declare(strict_types=1);

namespace FastD\Integrations\Governance;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final readonly class RetryPolicy
{
    /**
     * @param int[] $retryStatusCodes
     * @param string[] $retryMethods
     */
    public function __construct(
        public int $maxAttempts = 3,
        public int $baseDelayMs = 50,
        public int $maxDelayMs = 1000,
        public array $retryStatusCodes = [502, 503, 504],
        public array $retryMethods = ['GET', 'HEAD', 'OPTIONS', 'PUT', 'DELETE'],
    ) {
    }

    public function shouldRetry(
        RequestInterface $request,
        ?ResponseInterface $response,
        ?Throwable $error,
        int $attempt,
    ): bool {
        if ($attempt >= $this->maxAttempts) {
            return false;
        }

        if (!in_array(strtoupper($request->getMethod()), $this->retryMethods, true)) {
            return false;
        }

        if ($response !== null) {
            return in_array($response->getStatusCode(), $this->retryStatusCodes, true);
        }

        return $error !== null;
    }

    public function delayMs(int $attempt): int
    {
        $delay = $this->baseDelayMs * (2 ** max(0, $attempt - 1));

        return min($delay, $this->maxDelayMs);
    }
}
