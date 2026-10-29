<?php

declare(strict_types=1);

namespace FastD\Integrations\Discovery;

use FastD\Integrations\Etcd\EtcdClient;

final class EtcdResolver implements ResolverInterface
{
    /** @var array<string, array{expires: int, instances: ServiceInstance[]}> */
    private array $cache = [];

    public function __construct(
        private readonly EtcdClient $client,
        private readonly string $prefix = '/fastd/services',
        private readonly int $cacheTtl = 5,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function resolve(string $service): array
    {
        $now = $this->now();
        $cached = $this->cache[$service] ?? null;

        if ($cached !== null && $cached['expires'] > $now) {
            return $cached['instances'];
        }

        $instances = $this->fetch($service);
        $this->cache[$service] = [
            'expires' => $now + max(0, $this->cacheTtl),
            'instances' => $instances,
        ];

        return $instances;
    }

    public function refresh(string $service): array
    {
        unset($this->cache[$service]);

        return $this->resolve($service);
    }

    /**
     * @return ServiceInstance[]
     */
    private function fetch(string $service): array
    {
        $prefix = sprintf(
            '%s/%s/',
            trim($this->prefix, '/'),
            trim($service, '/'),
        );

        $instances = [];

        foreach ($this->client->getPrefix($prefix) as $keyValue) {
            $id = basename($keyValue->key);

            try {
                $instances[] = ServiceInstance::fromJson($service, $keyValue->value, $id);
            } catch (\Throwable) {
                continue;
            }
        }

        return $instances;
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }
}
