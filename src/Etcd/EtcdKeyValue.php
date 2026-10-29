<?php

declare(strict_types=1);

namespace FastD\Integrations\Etcd;

final readonly class EtcdKeyValue
{
    public function __construct(
        public string $key,
        public string $value,
        public int $lease = 0,
        public int $createRevision = 0,
        public int $modRevision = 0,
        public int $version = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            key: self::decode($data['key'] ?? ''),
            value: self::decode($data['value'] ?? ''),
            lease: (int) ($data['lease'] ?? 0),
            createRevision: (int) ($data['create_revision'] ?? 0),
            modRevision: (int) ($data['mod_revision'] ?? 0),
            version: (int) ($data['version'] ?? 0),
        );
    }

    private static function decode(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $decoded = base64_decode($value, true);

        return $decoded === false ? '' : $decoded;
    }
}
