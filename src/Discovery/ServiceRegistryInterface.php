<?php

declare(strict_types=1);

namespace FastD\Integrations\Discovery;

interface ServiceRegistryInterface
{
    public function register(ServiceInstance $instance): ServiceLease;

    public function deregister(ServiceLease $lease): void;

    public function heartbeat(ServiceLease $lease): void;
}
