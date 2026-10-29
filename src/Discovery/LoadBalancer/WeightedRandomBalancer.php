<?php

declare(strict_types=1);

namespace FastD\Integrations\Discovery\LoadBalancer;

use FastD\Integrations\Discovery\ServiceInstance;

final class WeightedRandomBalancer implements LoadBalancerInterface
{
    public function choose(array $instances): ?ServiceInstance
    {
        if ($instances === []) {
            return null;
        }

        $total = 0;

        foreach ($instances as $instance) {
            $total += max(1, $instance->weight);
        }

        $target = random_int(1, $total);

        foreach ($instances as $instance) {
            $target -= max(1, $instance->weight);

            if ($target <= 0) {
                return $instance;
            }
        }

        return $instances[array_key_last($instances)];
    }
}
