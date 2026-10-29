<?php

declare(strict_types=1);

namespace FastD\Integrations\Tests\HttpClient;

use FastD\Http\Factory;
use FastD\Http\Response\Json;
use FastD\Integrations\Discovery\LoadBalancer\RoundRobinBalancer;
use FastD\Integrations\Discovery\ServiceInstance;
use FastD\Integrations\Exception\ServiceUnavailableException;
use FastD\Integrations\HttpClient\ServiceClient;
use FastD\Integrations\Tests\Support\FakeClient;
use FastD\Integrations\Tests\Support\FakeResolver;
use PHPUnit\Framework\TestCase;

final class ServiceClientTest extends TestCase
{
    public function testResolvesServiceHostAndPort(): void
    {
        $factory = new Factory();
        $http = new FakeClient([new Json(200, ['ok' => true])]);
        $resolver = new FakeResolver();
        $resolver->set('user', [
            new ServiceInstance('user', '10.0.0.1', 9527),
        ]);

        $client = new ServiceClient($http, $resolver, new RoundRobinBalancer());
        $response = $client->sendRequest($factory->createRequest('GET', 'http://user/users/1?x=1'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('10.0.0.1', $http->requests[0]->getUri()->getHost());
        self::assertSame(9527, $http->requests[0]->getUri()->getPort());
        self::assertSame('/users/1', $http->requests[0]->getUri()->getPath());
        self::assertSame('x=1', $http->requests[0]->getUri()->getQuery());
    }

    public function testThrowsWhenNoInstanceAvailable(): void
    {
        $client = new ServiceClient(
            new FakeClient(),
            new FakeResolver(),
            new RoundRobinBalancer(),
        );

        $this->expectException(ServiceUnavailableException::class);
        $client->sendRequest((new Factory())->createRequest('GET', 'http://missing/'));
    }
}
