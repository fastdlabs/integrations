<?php

declare(strict_types=1);

namespace FastD\Integrations\Discovery;

final readonly class ServiceLease
{
    public function __construct(
        public ServiceInstance $instance,
        public string $key,
        public int $leaseId,
    ) {
    }
}
