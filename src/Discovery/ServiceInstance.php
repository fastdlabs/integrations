<?php

declare(strict_types=1);

namespace FastD\Integrations\Discovery;

use FastD\Integrations\Exception\DiscoveryException;
use JsonException;

final readonly class ServiceInstance
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $name,
        public string $host,
        public int $port,
        public int $weight = 100,
        public array $metadata = [],
        public string $id = '',
    ) {
    }

    public function endpoint(string $scheme = 'http'): string
    {
        $host = str_contains($this->host, ':') && !str_starts_with($this->host, '[')
            ? '[' . $this->host . ']'
            : $this->host;

        return sprintf('%s://%s:%d', $scheme, $host, $this->port);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'weight' => $this->weight,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @throws JsonException
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @throws JsonException
     */
    public static function fromJson(string $name, string $json, string $id = ''): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data) || !isset($data['host'], $data['port'])) {
            throw new DiscoveryException(sprintf(
                'Invalid service instance payload for "%s".',
                $name,
            ));
        }

        return new self(
            name: $name,
            host: (string) $data['host'],
            port: (int) $data['port'],
            weight: max(1, (int) ($data['weight'] ?? 100)),
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
            id: $id,
        );
    }
}
