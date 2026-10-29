<?php

declare(strict_types=1);

namespace FastD\Integrations\Tests\ServiceProvider;

use FastD\Container\Container;
use FastD\Integrations\Discovery\ServiceInstance;
use FastD\Integrations\ServiceProvider\IntegrationsServiceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;

final class IntegrationsServiceProviderTest extends TestCase
{
    public function testRegistersCoreServices(): void
    {
        $container = new Container();
        $container->add('integrations.config', [
            'etcd' => [
                'base_uri' => 'http://127.0.0.1:2379',
                'services_prefix' => '/fastd/services',
                'config_prefix' => '/fastd/config',
            ],
            'tracing' => ['enabled' => false],
            'service' => [
                'name' => 'user-service',
                'host' => '127.0.0.1',
                'port' => 9527,
            ],
        ]);

        (new IntegrationsServiceProvider())->register($container);

        self::assertTrue($container->has('integrations.etcd'));
        self::assertTrue($container->has('integrations.discovery.resolver'));
        self::assertTrue($container->has('integrations.discovery.registry'));
        self::assertTrue($container->has('integrations.config.source'));
        self::assertTrue($container->has('integrations.http.service_client'));
        self::assertInstanceOf(
            ClientInterface::class,
            $container->get('integrations.http.service_client'),
        );
        self::assertInstanceOf(
            ServiceInstance::class,
            $container->get('integrations.service_instance'),
        );
    }

    public function testRegistersTracingAndRateLimitMiddlewareOnMatcher(): void
    {
        $matcher = new class {
            /** @var object[] */
            public array $middleware = [];

            public function push(object $middleware): void
            {
                $this->middleware[] = $middleware;
            }
        };

        $container = new Container();
        $container->add('integrations.config', [
            'etcd' => ['base_uri' => 'http://127.0.0.1:2379'],
            'tracing' => ['enabled' => true],
            'governance' => [
                'rate_limit' => ['enabled' => true, 'capacity' => 10, 'refill_per_second' => 10],
            ],
        ]);
        $container->add('matcher', $matcher);

        (new IntegrationsServiceProvider())->register($container);

        self::assertTrue($container->has('integrations.tracing.middleware'));
        self::assertTrue($container->has('integrations.governance.rate_limit_middleware'));
        self::assertCount(2, $matcher->middleware);
    }

    public function testSkipsWhenEtcdIsNotConfigured(): void
    {
        $container = new Container();
        $container->add('integrations.config', []);

        (new IntegrationsServiceProvider())->register($container);

        self::assertFalse($container->has('integrations.etcd'));
        self::assertFalse($container->has('integrations.http.service_client'));
    }
}
