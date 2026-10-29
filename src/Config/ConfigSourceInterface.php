<?php

declare(strict_types=1);

namespace FastD\Integrations\Config;

interface ConfigSourceInterface
{
    /**
     * @return array<string, mixed>
     */
    public function load(string $service): array;
}
