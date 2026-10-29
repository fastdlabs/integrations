<?php

declare(strict_types=1);

namespace FastD\Integrations\Tracing;

final readonly class TraceContext
{
    public const ATTRIBUTE = 'fastd.trace_context';

    public function __construct(
        public string $traceId,
        public string $spanId,
        public bool $sampled = true,
    ) {
        if (!preg_match('/^[0-9a-f]{32}$/', $traceId) || $traceId === str_repeat('0', 32)) {
            throw new \InvalidArgumentException('Invalid W3C trace id.');
        }

        if (!preg_match('/^[0-9a-f]{16}$/', $spanId) || $spanId === str_repeat('0', 16)) {
            throw new \InvalidArgumentException('Invalid W3C span id.');
        }
    }

    public static function create(bool $sampled = true): self
    {
        return new self(
            traceId: bin2hex(random_bytes(16)),
            spanId: bin2hex(random_bytes(8)),
            sampled: $sampled,
        );
    }

    public static function fromTraceparent(?string $traceparent): self
    {
        if ($traceparent !== null && preg_match(
            '/^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/',
            $traceparent,
            $matches,
        )) {
            try {
                return new self(
                    traceId: $matches[1],
                    spanId: $matches[2],
                    sampled: ((int) hexdec($matches[3]) & 0x01) === 1,
                );
            } catch (\InvalidArgumentException) {
                // W3C 合法的全零 trace/span id 视为无效，重新生成。
            }
        }

        return self::create();
    }

    public function child(): self
    {
        return new self(
            traceId: $this->traceId,
            spanId: bin2hex(random_bytes(8)),
            sampled: $this->sampled,
        );
    }

    public function traceparent(): string
    {
        return sprintf(
            '00-%s-%s-%02x',
            $this->traceId,
            $this->spanId,
            $this->sampled ? 1 : 0,
        );
    }
}
