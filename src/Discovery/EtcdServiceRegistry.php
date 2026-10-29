<?php

declare(strict_types=1);

namespace FastD\Integrations\Discovery;

use FastD\Integrations\Etcd\EtcdClient;

final class EtcdServiceRegistry implements ServiceRegistryInterface
{
    public function __construct(
        private readonly EtcdClient $client,
        private readonly string $prefix = '/fastd/services',
        private readonly int $ttl = 15,
    ) {
    }

    public function register(ServiceInstance $instance): ServiceLease
    {
        $id = $instance->id !== ''
            ? $instance->id
            : bin2hex(random_bytes(8));

        $registered = new ServiceInstance(
            name: $instance->name,
            host: $instance->host,
            port: $instance->port,
            weight: $instance->weight,
            metadata: $instance->metadata,
            id: $id,
        );

        $leaseId = $this->client->grantLease($this->ttl);

        if ($leaseId <= 0) {
            throw new \RuntimeException('Unable to grant an etcd lease.');
        }

        $key = $this->key($instance->name, $id);
        $this->client->put($key, $registered->toJson(), $leaseId);

        return new ServiceLease($registered, $key, $leaseId);
    }

    public function deregister(ServiceLease $lease): void
    {
        $this->client->delete($lease->key);

        if ($lease->leaseId > 0) {
            $this->client->revokeLease($lease->leaseId);
        }
    }

    public function heartbeat(ServiceLease $lease): void
    {
        if ($lease->leaseId > 0) {
            $this->client->keepAlive($lease->leaseId);
        }
    }

    private function key(string $service, string $id): string
    {
        return sprintf(
            '/%s/%s/%s',
            trim($this->prefix, '/'),
            trim($service, '/'),
            $id,
        );
    }
}
