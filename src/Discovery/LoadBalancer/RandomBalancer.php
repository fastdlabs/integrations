<?php

declare(strict_types=1);

namespace FastD\Integrations\Discovery\LoadBalancer;

use FastD\Integrations\Discovery\ServiceInstance;

final class RandomBalancer implements LoadBalancerInterface
{
    public function choose(array $instances): ?ServiceInstance
    {
        if ($instances === []) {
            return null;
        }

        return $instances[random_int(0, count($instances) - 1)];
    }
}
