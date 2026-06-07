<?php

declare(strict_types=1);

namespace SymPress\Kernel\Location;

trait ResolverTrait
{
    private ?LocationResolver $resolver = null;

    private function injectResolver(LocationResolver $resolver): void
    {
        $this->resolver = $resolver;
    }

    private function resolver(): LocationResolver
    {
        if (!$this->resolver instanceof LocationResolver) {
            throw new \LogicException(sprintf('No location resolver found for %s.', static::class));
        }

        return $this->resolver;
    }

    public function resolveDir(string $name, string $path = '/'): ?string
    {
        return $this->resolver()->resolveDir($name, $path);
    }

    public function resolveUrl(string $name, string $path = '/'): ?string
    {
        return $this->resolver()->resolveUrl($name, $path);
    }

    public function pluginsDir(string $path = '/'): ?string
    {
        return $this->resolveDir(Locations::PLUGINS, $path);
    }

    public function pluginsUrl(string $path = '/'): ?string
    {
        return $this->resolveUrl(Locations::PLUGINS, $path);
    }

    public function muPluginsDir(string $path = '/'): ?string
    {
        return $this->resolveDir(Locations::MU_PLUGINS, $path);
    }

    public function muPluginsUrl(string $path = '/'): ?string
    {
        return $this->resolveUrl(Locations::MU_PLUGINS, $path);
    }

    public function themesDir(string $path = '/'): ?string
    {
        return $this->resolveDir(Locations::THEMES, $path);
    }

    public function themesUrl(string $path = '/'): ?string
    {
        return $this->resolveUrl(Locations::THEMES, $path);
    }

    public function languagesDir(): ?string
    {
        return $this->resolveDir(Locations::LANGUAGES, '/');
    }

    public function languagesUrl(): ?string
    {
        return $this->resolveUrl(Locations::LANGUAGES, '/');
    }

    public function contentDir(string $path = '/'): ?string
    {
        return $this->resolveDir(Locations::CONTENT, $path);
    }

    public function contentUrl(string $path = '/'): ?string
    {
        return $this->resolveUrl(Locations::CONTENT, $path);
    }

    public function vendorDir(string $path = '/'): ?string
    {
        return $this->resolveDir(Locations::VENDOR, $path);
    }

    public function vendorUrl(string $path = '/'): ?string
    {
        return $this->resolveUrl(Locations::VENDOR, $path);
    }

    public function rootDir(): ?string
    {
        return $this->resolveDir(Locations::ROOT, '/');
    }

    public function rootUrl(): ?string
    {
        return $this->resolveUrl(Locations::ROOT, '/');
    }
}
