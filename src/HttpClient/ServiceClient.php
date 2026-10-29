<?php

declare(strict_types=1);

namespace FastD\Integrations\HttpClient;

use FastD\Integrations\Discovery\LoadBalancer\LoadBalancerInterface;
use FastD\Integrations\Discovery\ResolverInterface;
use FastD\Integrations\Exception\ServiceUnavailableException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 把请求 URI 的 host 当作服务名，解析实例并替换目标地址。
 *
 * 只做 PSR-18 装饰，不实现 HTTP 协议；底层客户端仍可替换。
 */
final class ServiceClient implements ClientInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly ResolverInterface $resolver,
        private readonly LoadBalancerInterface $loadBalancer,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $service = $request->getUri()->getHost();
        $instances = $this->resolver->resolve($service);

        if ($instances === []) {
            throw new ServiceUnavailableException(sprintf(
                'No service instance available for "%s".',
                $service,
            ));
        }

        $instance = $this->loadBalancer->choose($instances);

        if ($instance === null) {
            throw new ServiceUnavailableException(sprintf(
                'No service instance selected for "%s".',
                $service,
            ));
        }

        $uri = $request->getUri()
            ->withHost($instance->host)
            ->withPort($instance->port);

        return $this->client->sendRequest($request->withUri($uri));
    }
}
