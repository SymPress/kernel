<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Support;

use SymPress\Kernel\EnvConfig;
use SymPress\Kernel\Location\Locations;

final class NullLocations implements Locations
{
    public static function createFromConfig(EnvConfig $config): self
    {
        return new self();
    }

    public function resolveDir(string $name, string $path = '/'): ?string
    {
        return null;
    }

    public function resolveUrl(string $name, string $path = '/'): ?string
    {
        return null;
    }

    public function pluginsDir(string $path = '/'): ?string
    {
        return null;
    }

    public function pluginsUrl(string $path = '/'): ?string
    {
        return null;
    }

    public function muPluginsDir(string $path = '/'): ?string
    {
        return null;
    }

    public function muPluginsUrl(string $path = '/'): ?string
    {
        return null;
    }

    public function themesDir(string $path = '/'): ?string
    {
        return null;
    }

    public function themesUrl(string $path = '/'): ?string
    {
        return null;
    }

    public function languagesDir(): ?string
    {
        return null;
    }

    public function languagesUrl(): ?string
    {
        return null;
    }

    public function contentDir(string $path = '/'): ?string
    {
        return null;
    }

    public function contentUrl(string $path = '/'): ?string
    {
        return null;
    }

    public function vendorDir(string $path = '/'): ?string
    {
        return null;
    }

    public function vendorUrl(string $path = '/'): ?string
    {
        return null;
    }

    public function rootDir(): ?string
    {
        return null;
    }

    public function rootUrl(): ?string
    {
        return null;
    }
}
