<?php

declare(strict_types=1);

namespace SymPress\Kernel\Location;

use SymPress\Kernel\EnvConfig;

final class WpEngineLocations implements Locations
{
    use ResolverTrait;

    public const string PRIVATE = 'private';

    public static function createFromConfig(EnvConfig $config): Locations
    {
        return new self($config);
    }

    private function __construct(EnvConfig $config)
    {
        $muDir = wp_normalize_path(trailingslashit((string) WP_CONTENT_DIR) . 'mu-plugins');

        $this->injectResolver(
            new LocationResolver(
                $config,
                [
                    LocationResolver::DIR => [
                        self::PRIVATE => wp_normalize_path((string) ABSPATH) . '_wpeprivate/',
                        self::VENDOR  => sprintf('%s/vendor/', $muDir),
                    ],
                    LocationResolver::URL => [
                        self::VENDOR => content_url('/mu-plugins/vendor/'),
                    ],
                ],
            ),
        );
    }

    public function privateDir(string $path = '/'): ?string
    {
        return $this->resolveDir(self::PRIVATE, $path);
    }
}
