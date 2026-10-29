<?php

declare(strict_types=1);

namespace FastD\Integrations\Discovery\LoadBalancer;

use FastD\Integrations\Discovery\ServiceInstance;

final class RoundRobinBalancer implements LoadBalancerInterface
{
    private int $counter = 0;

    public function choose(array $instances): ?ServiceInstance
    {
        if ($instances === []) {
            return null;
        }

        $index = $this->counter % count($instances);
        $this->counter++;

        return $instances[$index];
    }
}
