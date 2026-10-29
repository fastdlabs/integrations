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

## 安装

```bash
composer require fastd/integrations
```

本包只依赖 PSR 接口。`fastd/http` 仅作为开发测试依赖，用于构造 PSR-7 请求/响应。

## 配置示例

```php
use FastD\Http\Factory;
use FastD\Integrations\Discovery\EtcdResolver;
use FastD\Integrations\Discovery\LoadBalancer\RoundRobinBalancer;
use FastD\Integrations\Etcd\EtcdClient;
use FastD\Integrations\HttpClient\ServiceClient;

$factory = new Factory();

$etcd = new EtcdClient(
    httpClient: $psr18Client,
    requestFactory: $factory,
    streamFactory: $factory,
    baseUri: 'http://127.0.0.1:2379',
);

$resolver = new EtcdResolver($etcd, prefix: '/fastd/services');
$client = new ServiceClient($psr18Client, $resolver, new RoundRobinBalancer());

// 请求 URI 的 host 作为服务名
$request = $factory->createRequest('GET', 'http://user-service/users/1');
$response = $client->sendRequest($request);
```

## 服务注册

```php
use FastD\Integrations\Discovery\EtcdServiceRegistry;
use FastD\Integrations\Discovery\ServiceInstance;

$registry = new EtcdServiceRegistry($etcd, prefix: '/fastd/services', ttl: 15);

$lease = $registry->register(new ServiceInstance(
    name: 'user-service',
    host: '10.0.0.12',
    port: 9527,
    metadata: ['version' => '1.0.0'],
));

// 定时续约
$registry->heartbeat($lease);

// 进程退出
$registry->deregister($lease);
```

etcd key 结构：

```text
/fastd/services/{service}/{instanceId}
```

value 为 JSON：

```json
{"host":"10.0.0.12","port":9527,"weight":100,"metadata":{"version":"1.0.0"}}
```

## 配置中心

```php
use FastD\Integrations\Config\EtcdConfigSource;

$source = new EtcdConfigSource($etcd, prefix: '/fastd/config');
$config = $source->load('user-service');
```

etcd key：

```text
/fastd/config/user-service/database/host
```

会转换成：

```php
['database' => ['host' => '...']]
```

## 链路传播

在 FastD 中把中间件挂到全局中间件栈：

```php
use FastD\Integrations\Tracing\TracingMiddleware;

$container->get('matcher')->push(new TracingMiddleware());
```

入口读取或生成 `traceparent`，写入请求属性 `fastd.trace_context`，并返回 `X-Trace-Id`。下游调用使用：

```php
use FastD\Integrations\Tracing\TraceClient;

$client = new TraceClient($psr18Client, $traceContext);
```

`TraceClient` 只负责 W3C 上下文传播，不导出 Span。Span 采集/导出请接入 OpenTelemetry SDK。

## 治理

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

- 重试只对幂等方法生效
- 熔断和限流状态默认进程内；多实例共享需替换存储实现
- 生产环境需设置超时，避免重试放大故障

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
