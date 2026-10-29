<?php

declare(strict_types=1);

namespace FastD\Integrations\Etcd;

use FastD\Integrations\Exception\EtcdException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * etcd v3 HTTP gateway 的最小客户端。
 *
 * 不实现 etcd 协议本身，只负责把 JSON gateway 的 key/value/lease 调用封装成 PHP API。
 * Watch 属于长连接场景，建议由独立进程/协程接入成熟的 etcd 客户端；本类提供轮询所需的
 * getPrefix()、lease 和 revoke 能力。
 */
final class EtcdClient
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $baseUri = 'http://127.0.0.1:2379',
        /** @var array<string, string> */
        private readonly array $headers = [],
    ) {
    }

    public function put(string $key, string $value, int $leaseId = 0): void
    {
        $payload = [
            'key' => base64_encode($key),
            'value' => base64_encode($value),
        ];

        if ($leaseId > 0) {
            $payload['lease'] = $leaseId;
        }

        $this->request('/v3/kv/put', $payload);
    }

    /**
     * @return EtcdKeyValue[]
     */
    public function get(string $key, string $rangeEnd = ''): array
    {
        return $this->range($key, $rangeEnd);
    }

    /**
     * @return EtcdKeyValue[]
     */
    public function getPrefix(string $prefix): array
    {
        return $this->range($prefix, self::prefixEnd($prefix));
    }

    public function delete(string $key): void
    {
        $this->request('/v3/kv/deleterange', [
            'key' => base64_encode($key),
        ]);
    }

    public function deletePrefix(string $prefix): void
    {
        $this->request('/v3/kv/deleterange', [
            'key' => base64_encode($prefix),
            'range_end' => base64_encode(self::prefixEnd($prefix)),
        ]);
    }

    public function grantLease(int $ttl): int
    {
        $data = $this->request('/v3/lease/grant', ['TTL' => $ttl]);

        return (int) ($data['ID'] ?? 0);
    }

    public function keepAlive(int $leaseId): void
    {
        $this->request('/v3/lease/keepalive', ['ID' => $leaseId]);
    }

    public function revokeLease(int $leaseId): void
    {
        $this->request('/v3/lease/revoke', ['ID' => $leaseId]);
    }

    /**
     * @return EtcdKeyValue[]
     */
    private function range(string $key, string $rangeEnd): array
    {
        $payload = ['key' => base64_encode($key)];

        if ($rangeEnd !== '') {
            $payload['range_end'] = base64_encode($rangeEnd);
        }

        $data = $this->request('/v3/kv/range', $payload);
        $kvs = $data['kvs'] ?? [];

        if (!is_array($kvs)) {
            throw new EtcdException('Invalid etcd range response.');
        }

        return array_values(array_map(
            static fn (array $item): EtcdKeyValue => EtcdKeyValue::fromArray($item),
            array_filter($kvs, 'is_array'),
        ));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function request(string $path, array $payload): array
    {
        $uri = rtrim($this->baseUri, '/') . $path;

        $request = $this->requestFactory->createRequest('POST', $uri)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            )));

        foreach ($this->headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $response = $this->httpClient->sendRequest($request);
        $body = (string) $response->getBody();

        if ($response->getStatusCode() >= 400) {
            throw new EtcdException(sprintf(
                'etcd request failed: HTTP %d %s',
                $response->getStatusCode(),
                $body,
            ));
        }

        if ($body === '') {
            return [];
        }

        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw new EtcdException('Invalid etcd JSON response: ' . $body);
        }

        return $data;
    }

    public static function prefixEnd(string $prefix): string
    {
        if ($prefix === '') {
            return "\0";
        }

        for ($i = strlen($prefix) - 1; $i >= 0; $i--) {
            $byte = ord($prefix[$i]);

            if ($byte < 255) {
                $prefix[$i] = chr($byte + 1);

                return substr($prefix, 0, $i + 1);
            }
        }

        return "\0";
    }
}
