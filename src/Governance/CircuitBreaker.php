<?php

declare(strict_types=1);

namespace FastD\Integrations\Governance;

final class CircuitBreaker
{
    public const CLOSED = 'closed';
    public const OPEN = 'open';
    public const HALF_OPEN = 'half_open';

    private int $failures = 0;
    private int $openedAt = 0;
    private string $state = self::CLOSED;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(
        private readonly int $failureThreshold = 5,
        private readonly int $cooldownSeconds = 30,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function allow(): bool
    {
        $this->transition();

        return $this->state !== self::OPEN;
    }

    public function success(): void
    {
        $this->failures = 0;
        $this->state = self::CLOSED;
        $this->openedAt = 0;
    }

    public function failure(): void
    {
        $this->failures++;

        if ($this->state === self::HALF_OPEN || $this->failures >= $this->failureThreshold) {
            $this->state = self::OPEN;
            $this->openedAt = ($this->clock)();
        }
    }

    public function state(): string
    {
        $this->transition();

        return $this->state;
    }

    private function transition(): void
    {
        if ($this->state !== self::OPEN) {
            return;
        }

        if (($this->clock)() - $this->openedAt >= $this->cooldownSeconds) {
            $this->state = self::HALF_OPEN;
        }
    }
}
