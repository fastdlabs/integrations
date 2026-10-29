<?php

declare(strict_types=1);

namespace FastD\Integrations\Governance;

interface RateLimiterInterface
{
    public function attempt(string $key, int $tokens = 1): bool;
}
