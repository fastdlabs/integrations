<?php

declare(strict_types=1);

namespace FastD\Integrations\Discovery\LoadBalancer;

use FastD\Integrations\Discovery\ServiceInstance;

interface LoadBalancerInterface
{
    /**
     * @param ServiceInstance[] $instances
     */
    public function choose(array $instances): ?ServiceInstance;
}
