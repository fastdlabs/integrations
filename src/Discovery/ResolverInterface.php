<?php

declare(strict_types=1);

namespace FastD\Integrations\Discovery;

interface ResolverInterface
{
    /**
     * @return ServiceInstance[]
     */
    public function resolve(string $service): array;
}
