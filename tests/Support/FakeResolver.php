<?php

declare(strict_types=1);

namespace FastD\Integrations\Tests\Support;

use FastD\Integrations\Discovery\ResolverInterface;
use FastD\Integrations\Discovery\ServiceInstance;

final class FakeResolver implements ResolverInterface
{
    /** @var array<string, ServiceInstance[]> */
    public array $services = [];

    /**
     * @param ServiceInstance[] $instances
     */
    public function set(string $service, array $instances): void
    {
        $this->services[$service] = $instances;
    }

    public function resolve(string $service): array
    {
        return $this->services[$service] ?? [];
    }
}
