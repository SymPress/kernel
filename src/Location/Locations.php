<?php

declare(strict_types=1);

namespace SymPress\Kernel\Location;

use SymPress\Kernel\EnvConfig;

interface Locations
{
    public const string CONTENT = 'content';
    public const string VENDOR = 'vendor';
    public const string ROOT = 'root';
    public const string PLUGINS = 'plugins';
    public const string THEMES = 'themes';
    public const string MU_PLUGINS = 'mu-plugins';
    public const string LANGUAGES = 'languages';

    public static function createFromConfig(EnvConfig $config): self;

    public function resolveDir(string $name, string $path = '/'): ?string;

    public function resolveUrl(string $name, string $path = '/'): ?string;

    public function pluginsDir(string $path = '/'): ?string;

    public function pluginsUrl(string $path = '/'): ?string;

    public function muPluginsDir(string $path = '/'): ?string;

    public function muPluginsUrl(string $path = '/'): ?string;

    public function themesDir(string $path = '/'): ?string;

    public function themesUrl(string $path = '/'): ?string;

    public function languagesDir(): ?string;

    public function languagesUrl(): ?string;

    public function contentDir(string $path = '/'): ?string;

    public function contentUrl(string $path = '/'): ?string;

    public function vendorDir(string $path = '/'): ?string;

    public function vendorUrl(string $path = '/'): ?string;

    public function rootDir(): ?string;

    public function rootUrl(): ?string;
}
