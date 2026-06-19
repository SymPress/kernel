<?php

declare(strict_types=1);

namespace SymPress\Kernel\Location;

use SymPress\Kernel\EnvConfig;

final class VipLocations implements Locations
{
    use ResolverTrait;

    public const string CLIENT_MU_PLUGINS = 'client-mu-plugins';
    public const string VIP_CONFIG = 'vip-config';
    public const string IMAGES = 'images';
    public const string PRIVATE = 'private';

    public static function createFromConfig(EnvConfig $config): Locations
    {
        return new self($config);
    }

    private function __construct(EnvConfig $config)
    {
        $baseResolver = new LocationResolver($config);
        $contentUrl = $baseResolver->resolveUrl(self::CONTENT) ?? '';
        $contentDir = $baseResolver->resolveDir(self::CONTENT) ?? '';
        $privateDir = defined('WPCOM_VIP_PRIVATE_DIR')
            ? trailingslashit(wp_normalize_path($this->constantString('WPCOM_VIP_PRIVATE_DIR')))
            : null;
        $clientMuDir = defined('WPCOM_VIP_CLIENT_MU_PLUGIN_DIR')
            ? trailingslashit(wp_normalize_path($this->constantString('WPCOM_VIP_CLIENT_MU_PLUGIN_DIR')))
            : sprintf('%sclient-mu-plugins/', $contentDir);
        $clientMuUrl = sprintf('%sclient-mu-plugins/', $contentUrl);
        $abspath = trailingslashit(wp_normalize_path($this->constantString('ABSPATH')));
        $directories = [
            self::IMAGES            => sprintf('%simages/', $contentDir),
            self::CLIENT_MU_PLUGINS => $clientMuDir,
            self::VENDOR            => sprintf('%svendor/', $clientMuDir),
            self::VIP_CONFIG        => sprintf('%svip-config/', $abspath),
        ];

        if ($privateDir !== null) {
            $directories[self::PRIVATE] = $privateDir;
        }

        $this->injectResolver(
            new LocationResolver(
                $config,
                [
                    LocationResolver::DIR => $directories,
                    LocationResolver::URL => [
                        self::IMAGES            => sprintf('%simages', $contentUrl),
                        self::CLIENT_MU_PLUGINS => $clientMuUrl,
                        self::VENDOR            => sprintf('%svendor/', $clientMuUrl),
                    ],
                ],
            ),
        );
    }

    private function constantString(string $name): string
    {
        if (!defined($name)) {
            return '';
        }

        $value = constant($name);

        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return '';
    }

    public function clientMuPluginsDir(string $path = '/'): ?string
    {
        return $this->resolveDir(self::CLIENT_MU_PLUGINS, $path);
    }

    public function clientMuPluginsUrl(string $path = '/'): ?string
    {
        return $this->resolveUrl(self::CLIENT_MU_PLUGINS, $path);
    }

    public function imagesDir(string $path = '/'): ?string
    {
        return $this->resolveDir(self::IMAGES, $path);
    }

    public function imagesUrl(string $path = '/'): ?string
    {
        return $this->resolveUrl(self::IMAGES, $path);
    }

    public function privateDir(string $path = '/'): ?string
    {
        return $this->resolveDir(self::PRIVATE, $path);
    }

    public function vipConfigDir(string $path = '/'): ?string
    {
        return $this->resolveDir(self::VIP_CONFIG, $path);
    }
}
