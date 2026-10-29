<?php

declare(strict_types=1);

namespace FastD\Integrations\Tests\Config;

use FastD\Http\Factory;
use FastD\Http\Response\Json;
use FastD\Integrations\Config\EtcdConfigSource;
use FastD\Integrations\Etcd\EtcdClient;
use FastD\Integrations\Tests\Support\FakeClient;
use PHPUnit\Framework\TestCase;

final class EtcdConfigSourceTest extends TestCase
{
    public function testLoadBuildsNestedConfig(): void
    {
        $factory = new Factory();
        $http = new FakeClient([
            new Json(200, [
                'kvs' => [
                    [
                        'key' => base64_encode('/fastd/config/user/database/host'),
                        'value' => base64_encode('db.local'),
                    ],
                    [
                        'key' => base64_encode('/fastd/config/user/database/port'),
                        'value' => base64_encode('3306'),
                    ],
                    [
                        'key' => base64_encode('/fastd/config/user/name'),
                        'value' => base64_encode('user-service'),
                    ],
                ],
            ]),
        ]);

        $source = new EtcdConfigSource(
            new EtcdClient($http, $factory, $factory),
            '/fastd/config',
        );

        self::assertSame([
            'database' => [
                'host' => 'db.local',
                'port' => '3306',
            ],
            'name' => 'user-service',
        ], $source->load('user'));
    }
}
