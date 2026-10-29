<?php

declare(strict_types=1);

namespace FastD\Integrations\Tests\Discovery;

use FastD\Http\Factory;
use FastD\Http\Response\Json;
use FastD\Integrations\Discovery\EtcdResolver;
use FastD\Integrations\Discovery\EtcdServiceRegistry;
use FastD\Integrations\Discovery\LoadBalancer\RoundRobinBalancer;
use FastD\Integrations\Discovery\LoadBalancer\WeightedRandomBalancer;
use FastD\Integrations\Discovery\ServiceInstance;
use FastD\Integrations\Etcd\EtcdClient;
use FastD\Integrations\Tests\Support\FakeClient;
use PHPUnit\Framework\TestCase;

final class DiscoveryTest extends TestCase
{
    public function testRegistryRegistersLeaseAndPayload(): void
    {
        $factory = new Factory();
        $http = new FakeClient([
            new Json(200, ['ID' => 9]),
            new Json(200, []),
            new Json(200, []),
            new Json(200, []),
            new Json(200, []),
        ]);
        $registry = new EtcdServiceRegistry(
            new EtcdClient($http, $factory, $factory),
            '/fastd/services',
            15,
        );

        $lease = $registry->register(new ServiceInstance(
            name: 'user',
            host: '10.0.0.1',
            port: 9527,
            metadata: ['version' => '1.0'],
        ));

        self::assertSame(9, $lease->leaseId);
        self::assertStringContainsString('/fastd/services/user/', $lease->key);

        $put = json_decode((string) $http->requests[1]->getBody(), true);
        $payload = json_decode(base64_decode($put['value']), true);
        self::assertSame('10.0.0.1', $payload['host']);
        self::assertSame(9527, $payload['port']);

        $registry->heartbeat($lease);
        $registry->deregister($lease);
        self::assertCount(5, $http->requests);
    }

    public function testResolverBuildsInstancesAndCaches(): void
    {
        $factory = new Factory();
        $response = new Json(200, [
            'kvs' => [
                [
                    'key' => base64_encode('/fastd/services/user/a'),
                    'value' => base64_encode('{"host":"10.0.0.1","port":9527,"weight":100}'),
                ],
                [
                    'key' => base64_encode('/fastd/services/user/b'),
                    'value' => base64_encode('{"host":"10.0.0.2","port":9528,"weight":200}'),
                ],
            ],
        ]);
        $http = new FakeClient([$response, $response]);

        $now = 100;
        $resolver = new EtcdResolver(
            new EtcdClient($http, $factory, $factory),
            cacheTtl: 30,
            clock: static function () use (&$now): int {
                return $now;
            },
        );

        $instances = $resolver->resolve('user');
        self::assertCount(2, $instances);
        self::assertSame('a', $instances[0]->id);
        self::assertSame(200, $instances[1]->weight);

        $resolver->resolve('user');
        self::assertCount(1, $http->requests);

        $now += 31;
        $resolver->resolve('user');
        self::assertCount(2, $http->requests);
    }

    public function testLoadBalancersChooseInstances(): void
    {
        $instances = [
            new ServiceInstance('user', '10.0.0.1', 1),
            new ServiceInstance('user', '10.0.0.2', 2),
        ];

        $rr = new RoundRobinBalancer();
        self::assertSame($instances[0], $rr->choose($instances));
        self::assertSame($instances[1], $rr->choose($instances));
        self::assertSame($instances[0], $rr->choose($instances));

        $weighted = new WeightedRandomBalancer();
        self::assertContains($weighted->choose($instances), $instances);
        self::assertNull($weighted->choose([]));
    }
}
