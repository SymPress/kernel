<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Support;

use SymPress\Kernel\Location\Locations;
use SymPress\Kernel\SiteConfig;

final class TestSiteConfig implements SiteConfig
{
    public function __construct(
        private readonly string $environment = 'test',
        private readonly string $hosting = self::HOSTING_OTHER,
        private readonly array $data = [],
        private readonly ?Locations $locations = null,
    ) {
    }

    public function locations(): Locations
    {
        return $this->locations ?? new NullLocations();
    }

    public function hosting(): string
    {
        return $this->hosting;
    }

    public function hostingIs(string $hosting): bool
    {
        return $this->hosting() === $hosting;
    }

    public function env(): string
    {
        return $this->environment;
    }

    public function envIs(string $env): bool
    {
        return $this->env() === $env;
    }

    public function get(string $name, mixed $default = null): mixed
    {
        return $this->data[$name] ?? $default;
    }

    public function jsonSerialize(): array
    {
        return $this->data;
    }
}
