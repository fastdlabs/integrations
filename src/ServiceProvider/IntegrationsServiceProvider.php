<?php

declare(strict_types=1);

namespace FastD\Integrations\ServiceProvider;

use FastD\Container\Container;
use FastD\Container\ServiceProviderInterface;
use FastD\Http\Factory;
use FastD\Http\Request\Client;
use FastD\Integrations\Config\EtcdConfigSource;
use FastD\Integrations\Discovery\EtcdResolver;
use FastD\Integrations\Discovery\EtcdServiceRegistry;
use FastD\Integrations\Discovery\LoadBalancer\RandomBalancer;
use FastD\Integrations\Discovery\LoadBalancer\RoundRobinBalancer;
use FastD\Integrations\Discovery\LoadBalancer\WeightedRandomBalancer;
use FastD\Integrations\Discovery\ServiceInstance;
use FastD\Integrations\Etcd\EtcdClient;
use FastD\Integrations\Governance\CircuitBreaker;
use FastD\Integrations\Governance\CircuitBreakerClient;
use FastD\Integrations\Governance\InMemoryRateLimiter;
use FastD\Integrations\Governance\RateLimitMiddleware;
use FastD\Integrations\Governance\RetryClient;
use FastD\Integrations\Governance\RetryPolicy;
use FastD\Integrations\HttpClient\ServiceClient;
use FastD\Integrations\Tracing\TracingMiddleware;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Client\ClientInterface;

/**
 * 将可选集成模块装配到 FastD 应用。
 *
 * 配置来源：
 * 1. 容器中显式注册的 `integrations.config` 数组；
 * 2. 应用根目录下的 config/integrations.php。
 *
 * 本 Provider 只负责装配，不实现 etcd、限流、链路后端。
 */
final class IntegrationsServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $config = $this->config($container);

        $this->registerTracing($container, $config);
        $this->registerRateLimit($container, $config);

        $etcdConfig = (array) ($config['etcd'] ?? []);

        if ($etcdConfig === [] || empty($etcdConfig['base_uri'])) {
            return;
        }

        $factory = new Factory();
        $httpClient = new Client();

        $etcd = new EtcdClient(
            httpClient: $httpClient,
            requestFactory: $factory,
            streamFactory: $factory,
            baseUri: (string) $etcdConfig['base_uri'],
            headers: (array) ($etcdConfig['headers'] ?? []),
        );

        $resolver = new EtcdResolver(
            client: $etcd,
            prefix: (string) ($etcdConfig['services_prefix'] ?? '/fastd/services'),
            cacheTtl: (int) ($etcdConfig['cache_ttl'] ?? 5),
        );

        $registry = new EtcdServiceRegistry(
            client: $etcd,
            prefix: (string) ($etcdConfig['services_prefix'] ?? '/fastd/services'),
            ttl: (int) ($etcdConfig['lease_ttl'] ?? 15),
        );

        $container->add('integrations.etcd', $etcd);
        $container->add('integrations.discovery.resolver', $resolver);
        $container->add('integrations.discovery.registry', $registry);

        $container->add(
            'integrations.http.service_client',
            $this->serviceClient($httpClient, $resolver, $config),
        );

        $container->add(
            'integrations.config.source',
            new EtcdConfigSource(
                $etcd,
                (string) ($etcdConfig['config_prefix'] ?? '/fastd/config'),
            ),
        );

        if (isset($config['service']) && is_array($config['service']) && $config['service'] !== []) {
            $service = $config['service'];
            $container->add('integrations.service_instance', new ServiceInstance(
                name: (string) ($service['name'] ?? ''),
                host: (string) ($service['host'] ?? '127.0.0.1'),
                port: (int) ($service['port'] ?? 0),
                weight: (int) ($service['weight'] ?? 100),
                metadata: (array) ($service['metadata'] ?? []),
            ));
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function serviceClient(
        ClientInterface $httpClient,
        EtcdResolver $resolver,
        array $config,
    ): ClientInterface {
        $balancer = match ((string) ($config['etcd']['load_balancer'] ?? 'round_robin')) {
            'random' => new RandomBalancer(),
            'weighted_random' => new WeightedRandomBalancer(),
            default => new RoundRobinBalancer(),
        };

        $client = new ServiceClient($httpClient, $resolver, $balancer);

        $governance = (array) ($config['governance'] ?? []);

        if (!empty($governance['circuit_breaker']['enabled'])) {
            $breaker = (array) $governance['circuit_breaker'];
            $client = new CircuitBreakerClient($client, new CircuitBreaker(
                failureThreshold: (int) ($breaker['failure_threshold'] ?? 5),
                cooldownSeconds: (int) ($breaker['cooldown_seconds'] ?? 30),
            ));
        }

        if (!empty($governance['retry']['enabled'])) {
            $retry = (array) $governance['retry'];
            $client = new RetryClient($client, new RetryPolicy(
                maxAttempts: (int) ($retry['max_attempts'] ?? 3),
                baseDelayMs: (int) ($retry['base_delay_ms'] ?? 50),
                maxDelayMs: (int) ($retry['max_delay_ms'] ?? 1000),
            ));
        }

        return $client;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerTracing(Container $container, array $config): void
    {
        $tracing = (array) ($config['tracing'] ?? []);

        if (empty($tracing['enabled'])) {
            return;
        }

        $middleware = new TracingMiddleware((bool) ($tracing['trust_incoming'] ?? true));
        $container->add('integrations.tracing.middleware', $middleware);

        if ($container->has('matcher')) {
            $container->get('matcher')->push($middleware);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerRateLimit(Container $container, array $config): void
    {
        $governance = (array) ($config['governance'] ?? []);
        $rateLimit = (array) ($governance['rate_limit'] ?? []);

        if (empty($rateLimit['enabled'])) {
            return;
        }

        $limiter = new InMemoryRateLimiter(
            capacity: (int) ($rateLimit['capacity'] ?? 60),
            refillPerSecond: (int) ($rateLimit['refill_per_second'] ?? 60),
        );

        $rateLimitMiddleware = new RateLimitMiddleware($limiter, new Factory());
        $container->add('integrations.governance.rate_limiter', $limiter);
        $container->add('integrations.governance.rate_limit_middleware', $rateLimitMiddleware);

        if ($container->has('matcher')) {
            $container->get('matcher')->push($rateLimitMiddleware);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function config(Container $container): array
    {
        try {
            if ($container->has('integrations.config')) {
                $config = $container->get('integrations.config');

                return is_array($config) ? $config : [];
            }
        } catch (NotFoundExceptionInterface|ContainerExceptionInterface) {
            // 继续回退到配置文件。
        }

        $file = getcwd() . '/config/integrations.php';

        if (is_file($file)) {
            $config = require $file;

            return is_array($config) ? $config : [];
        }

        return [];
    }
}
