<?php

declare(strict_types=1);

return [
    'etcd' => [
        'base_uri' => getenv('ETCD_ENDPOINT') ?: 'http://127.0.0.1:2379',
        'headers' => [],
        'services_prefix' => '/fastd/services',
        'config_prefix' => '/fastd/config',
        'cache_ttl' => 5,
        'lease_ttl' => 15,
        'load_balancer' => 'round_robin',
    ],

    'tracing' => [
        'enabled' => true,
        'trust_incoming' => true,
    ],

    'governance' => [
        'retry' => [
            'enabled' => false,
            'max_attempts' => 3,
            'base_delay_ms' => 50,
            'max_delay_ms' => 1000,
        ],
        'circuit_breaker' => [
            'enabled' => false,
            'failure_threshold' => 5,
            'cooldown_seconds' => 30,
        ],
        'rate_limit' => [
            'enabled' => false,
            'capacity' => 60,
            'refill_per_second' => 60,
        ],
    ],

    'service' => [
        'name' => 'user-service',
        'host' => '127.0.0.1',
        'port' => 9527,
        'weight' => 100,
        'metadata' => [],
    ],
];
