<?php

declare(strict_types=1);

namespace FastD\Integrations\Config;

use FastD\Integrations\Etcd\EtcdClient;

/**
 * 把 etcd 的层级 key 读成嵌套数组。
 *
 * key:  /fastd/config/user-service/database/host
 * load: user-service
 * 结果: ['database' => ['host' => '...']]
 */
final class EtcdConfigSource implements ConfigSourceInterface
{
    public function __construct(
        private readonly EtcdClient $client,
        private readonly string $prefix = '/fastd/config',
    ) {
    }

    public function load(string $service): array
    {
        $prefix = sprintf(
            '%s/%s/',
            trim($this->prefix, '/'),
            trim($service, '/'),
        );

        $config = [];

        foreach ($this->client->getPrefix($prefix) as $keyValue) {
            $path = substr($keyValue->key, strlen($prefix));

            if ($path === false || $path === '') {
                continue;
            }

            $this->assign($config, explode('/', trim($path, '/')), $keyValue->value);
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @param string[] $path
     */
    private function assign(array &$config, array $path, string $value): void
    {
        $cursor = &$config;

        foreach ($path as $index => $segment) {
            if ($segment === '') {
                continue;
            }

            if ($index === array_key_last($path)) {
                $cursor[$segment] = $value;
                break;
            }

            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }
    }
}
