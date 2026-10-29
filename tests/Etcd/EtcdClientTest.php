<?php

declare(strict_types=1);

namespace FastD\Integrations\Tests\Etcd;

use FastD\Http\Factory;
use FastD\Http\Response\Json;
use FastD\Integrations\Etcd\EtcdClient;
use FastD\Integrations\Tests\Support\FakeClient;
use PHPUnit\Framework\TestCase;

final class EtcdClientTest extends TestCase
{
    public function testGetPrefixDecodesBase64(): void
    {
        $factory = new Factory();
        $http = new FakeClient([
            new Json(200, [
                'kvs' => [
                    [
                        'key' => base64_encode('/fastd/services/user/a'),
                        'value' => base64_encode('{"host":"127.0.0.1"}'),
                    ],
                ],
            ]),
        ]);

        $client = new EtcdClient($http, $factory, $factory, 'http://etcd:2379');
        $items = $client->getPrefix('/fastd/services/user/');

        self::assertCount(1, $items);
        self::assertSame('/fastd/services/user/a', $items[0]->key);
        self::assertSame('{"host":"127.0.0.1"}', $items[0]->value);
        self::assertStringContainsString('/v3/kv/range', (string) $http->requests[0]->getUri());
    }

    public function testPutGrantAndKeepAliveUseEtcdGatewayPayloads(): void
    {
        $factory = new Factory();
        $http = new FakeClient([
            new Json(200, ['ID' => 42]),
            new Json(200, []),
            new Json(200, []),
        ]);

        $client = new EtcdClient($http, $factory, $factory, 'http://etcd:2379');
        $lease = $client->grantLease(15);
        $client->put('/fastd/services/a', '{"host":"10.0.0.1"}', $lease);
        $client->keepAlive($lease);

        self::assertSame(42, $lease);

        $put = json_decode((string) $http->requests[1]->getBody(), true);
        self::assertSame(base64_encode('/fastd/services/a'), $put['key']);
        self::assertSame(42, $put['lease']);
    }

    public function testPrefixEndHandlesNormalAndFullBytePrefix(): void
    {
        self::assertSame('/b', EtcdClient::prefixEnd('/a'));
        self::assertSame("\0", EtcdClient::prefixEnd("\xff"));
    }
}
