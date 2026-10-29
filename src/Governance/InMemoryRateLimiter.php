<?php

declare(strict_types=1);

namespace FastD\Integrations\Governance;

/**
 * 进程内令牌桶。
 *
 * 只适合单 worker 或演示；多实例全局限流应替换为 Redis/网关实现。
 */
final class InMemoryRateLimiter implements RateLimiterInterface
{
    /** @var array<string, array{tokens: float, updated: int}> */
    private array $buckets = [];

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(
        private readonly int $capacity = 60,
        private readonly int $refillPerSecond = 60,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function attempt(string $key, int $tokens = 1): bool
    {
        $now = ($this->clock)();
        $bucket = $this->buckets[$key] ?? [
            'tokens' => (float) $this->capacity,
            'updated' => $now,
        ];

        $elapsed = max(0, $now - $bucket['updated']);
        $bucket['tokens'] = min(
            (float) $this->capacity,
            $bucket['tokens'] + $elapsed * $this->refillPerSecond,
        );
        $bucket['updated'] = $now;

        if ($bucket['tokens'] < $tokens) {
            $this->buckets[$key] = $bucket;

            return false;
        }

        $bucket['tokens'] -= $tokens;
        $this->buckets[$key] = $bucket;

        return true;
    }
}
