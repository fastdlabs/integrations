# fastd/integrations

FastD 的可选微服务集成包。

定位是 **集成层**，不是微服务治理平台：

- FastD 负责适配、装配、中间件和客户端包装
- etcd、OpenTelemetry、限流/熔断算法仍由外部实现
- 不把注册中心、RPC、网关或分布式事务塞进 FastD 核心

## 模块

| 模块 | 作用 |
|---|---|
| `Etcd` | etcd v3 HTTP gateway 客户端 |
| `Discovery` | 服务注册、服务解析、实例负载均衡 |
| `Config` | 从 etcd 读取配置中心键值 |
| `HttpClient` | 按服务名解析实例的 PSR-18 客户端 |
| `Tracing` | W3C `traceparent` 提取、生成与下游传播 |
| `Governance` | 重试、熔断、令牌桶限流 |
| `ServiceProvider` | FastD 容器装配、中间件注册、配置读取 |

## 安装

```bash
composer require fastd/integrations
```

> 当前功能分支为 `feat/initial-integrations`，正式发布前使用 VCS 方式引用。

如果包尚未发布到 Packagist，可临时使用 VCS。合并到 master 后可将版本改为 `dev-master`：

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/fastdlabs/integrations"
        }
    ],
    "require": {
        "fastd/integrations": "dev-feat/initial-integrations"
    }
}
```

## FastD 集成

### 1. 配置文件

新增 `config/integrations.php`：

```php
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
        'load_balancer' => 'round_robin', // round_robin | random | weighted_random
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

    // 当前服务实例；Provider 只注册为服务，不自动注册到 etcd。
    'service' => [
        'name' => 'user-service',
        'host' => '127.0.0.1',
        'port' => 9527,
        'weight' => 100,
        'metadata' => [],
    ],
];
```

Provider 的配置来源：

1. 容器中的 `integrations.config` 数组；
2. 应用根目录的 `config/integrations.php`。

如果没有配置 `etcd.base_uri`，Provider 直接跳过，不影响应用启动。

### 2. 注册 ServiceProvider

`config/service.php`：

```php
return [
    \FastD\Integrations\ServiceProvider\IntegrationsServiceProvider::class,
];
```

注册后容器中可使用：

| 服务 ID | 类型 |
|---|---|
| `integrations.etcd` | `EtcdClient` |
| `integrations.discovery.resolver` | `EtcdResolver` |
| `integrations.discovery.registry` | `EtcdServiceRegistry` |
| `integrations.http.service_client` | 已按配置包装的 PSR-18 Client |
| `integrations.config.source` | `EtcdConfigSource` |
| `integrations.tracing.middleware` | `TracingMiddleware`（启用时） |
| `integrations.governance.rate_limiter` | `InMemoryRateLimiter`（启用时） |
| `integrations.governance.rate_limit_middleware` | `RateLimitMiddleware`（启用时） |
| `integrations.service_instance` | `ServiceInstance`（配置 service 时） |

`matcher` 已存在时，Provider 会自动把 tracing / rate limit 中间件 push 到全局中间件栈。

### 3. 服务间调用

```php
use FastD\Http\Factory;
use FastD\Integrations\Tracing\TraceClient;
use FastD\Integrations\Tracing\TraceContext;

$factory = new Factory();
$client = container()->get('integrations.http.service_client');

// 当前进入的 PSR-7 ServerRequest
$context = $incomingRequest->getAttribute(TraceContext::ATTRIBUTE)
    ?? TraceContext::create();

// host 是服务名，不是真实域名
$request = $factory->createRequest('GET', 'http://user-service/users/1');

$traceClient = new TraceClient($client, $context);
$response = $traceClient->sendRequest($request);
```

`ServiceClient` 的行为：

```text
http://user-service/users/1
  → Resolver 从 /fastd/services/user-service 读取实例
  → LoadBalancer 选择一个实例
  → 替换为 http://10.0.0.12:9527/users/1
  → 交给底层 PSR-18 Client
```

### 4. 注册当前服务

Provider 只在容器中创建 `service_instance`，不会自动注册，避免服务尚未监听端口就提前注册。

在服务启动完成后的生命周期中执行：

```php
use Swoole\Timer;

$registry = container()->get('integrations.discovery.registry');
$instance = container()->get('integrations.service_instance');

$lease = $registry->register($instance);

Timer::tick(5000, static function () use ($registry, $lease): void {
    $registry->heartbeat($lease);
});

// 进程退出时
$registry->deregister($lease);
```

etcd key 结构：

```text
/fastd/services/{service}/{instanceId}
```

value：

```json
{
  "host": "10.0.0.12",
  "port": 9527,
  "weight": 100,
  "metadata": {
    "version": "1.0.0"
  }
}
```

### 5. 配置中心

```php
$source = container()->get('integrations.config.source');

$config = $source->load('user-service');
```

etcd：

```text
/fastd/config/user-service/database/host = db.local
/fastd/config/user-service/database/port = 3306
```

结果：

```php
[
    'database' => [
        'host' => 'db.local',
        'port' => '3306',
    ],
]
```

本包只读取和转换配置，不自动替换 FastD 本地配置。需要时在业务 Provider 中合并：

```php
$container->add('remote_config', $config);
```

### 6. 链路传播

`TracingMiddleware` 已由 Provider 挂载时，可直接读取请求上下文：

```php
use FastD\Integrations\Tracing\TraceContext;

$context = $incomingRequest->getAttribute(TraceContext::ATTRIBUTE);
```

下游调用使用 `TraceClient` 注入 `traceparent`。

本包只做 W3C 上下文传播，不导出 Span。Span 采集/导出请接入 OpenTelemetry SDK。

### 7. 重试与熔断

在 `config/integrations.php` 开启后，`integrations.http.service_client` 会自动包装：

```php
'governance' => [
    'retry' => [
        'enabled' => true,
        'max_attempts' => 3,
    ],
    'circuit_breaker' => [
        'enabled' => true,
        'failure_threshold' => 5,
        'cooldown_seconds' => 30,
    ],
],
```

也可以手动组合：

```php
use FastD\Integrations\Governance\CircuitBreaker;
use FastD\Integrations\Governance\CircuitBreakerClient;
use FastD\Integrations\Governance\RetryClient;
use FastD\Integrations\Governance\RetryPolicy;

$client = new RetryClient(
    new CircuitBreakerClient($psr18Client, new CircuitBreaker()),
    new RetryPolicy(maxAttempts: 3),
);
```

默认只重试 `GET`、`HEAD`、`OPTIONS`、`PUT`、`DELETE`。`POST` 需要业务确认幂等后再加入重试策略。

### 8. 限流

开启：

```php
'governance' => [
    'rate_limit' => [
        'enabled' => true,
        'capacity' => 60,
        'refill_per_second' => 60,
    ],
],
```

Provider 会创建 `RateLimitMiddleware` 并 push 到 `matcher`。默认按 `X-Api-Key` / `X-Forwarded-For` 限流。

进程内令牌桶只适合单 worker 或演示；多实例全局限流请替换为 Redis 或网关实现。

## 独立使用

不使用 FastD Provider 时，也可以直接组合组件：

```php
use FastD\Http\Factory;
use FastD\Http\Request\Client;
use FastD\Integrations\Discovery\EtcdResolver;
use FastD\Integrations\Discovery\LoadBalancer\RoundRobinBalancer;
use FastD\Integrations\Etcd\EtcdClient;
use FastD\Integrations\HttpClient\ServiceClient;

$factory = new Factory();
$psr18Client = new Client();

$etcd = new EtcdClient(
    httpClient: $psr18Client,
    requestFactory: $factory,
    streamFactory: $factory,
    baseUri: 'http://127.0.0.1:2379',
);

$resolver = new EtcdResolver($etcd, prefix: '/fastd/services');
$client = new ServiceClient($psr18Client, $resolver, new RoundRobinBalancer());

$request = $factory->createRequest('GET', 'http://user-service/users/1');
$response = $client->sendRequest($request);
```

## 边界

本包不提供：

- 服务注册中心本身
- gRPC/Thrift 协议
- 分布式事务
- 完整 OpenTelemetry SDK
- 全局限流存储

这些应由 etcd、OpenTelemetry、网关或独立库负责。

## 测试

```bash
composer install
vendor/bin/phpunit
```

当前单元测试使用 fake PSR-18 客户端，不依赖真实 etcd。生产接入前应增加真实 etcd 的集成测试。
