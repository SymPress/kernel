<?php

declare(strict_types=1);

namespace SymPress\Kernel\Location;

use SymPress\Kernel\EnvConfig;

final class LocationResolver
{
    public const string URL = 'url';
    public const string DIR = 'dir';

    private const array CONTENT_LOCATIONS = [
        Locations::MU_PLUGINS => 'mu-plugins/',
        Locations::LANGUAGES => 'languages/',
        Locations::PLUGINS => 'plugins/',
        Locations::THEMES => 'themes/',
    ];

    /**
     * @var array<string, array<string, string|null>>
     */
    private array $locations;

    /**
     * @param array<string, array<string, string>> $extendedDefaults
     */
    public function __construct(
        private readonly EnvConfig $config,
        array $extendedDefaults = [],
    ) {
        $vendorPath = $this->discoverVendorPath();
        $contentPath = trailingslashit(wp_normalize_path((string) WP_CONTENT_DIR));
        $contentUrl = content_url('/');
        $vendorUrl = null;

        if ($vendorPath !== null && str_starts_with($vendorPath, $contentPath)) {
            $subFolder = substr($vendorPath, strlen($contentPath));
            $vendorUrl = $contentUrl . (string) $subFolder;
        }

        $locations = [
            self::DIR => [
                Locations::ROOT => trailingslashit((string) ABSPATH),
                Locations::VENDOR => $vendorPath,
                Locations::CONTENT => $contentPath,
            ],
            self::URL => [
                Locations::ROOT => network_site_url('/'),
                Locations::VENDOR => $vendorUrl,
                Locations::CONTENT => $contentUrl,
            ],
        ];

        $custom = $extendedDefaults !== [] ? $this->parseExtendedDefaults($extendedDefaults) : [];

        if (($custom[self::DIR] ?? null) !== null) {
            $locations[self::DIR] = array_merge($locations[self::DIR], $custom[self::DIR]);
        }

        if (($custom[self::URL] ?? null) !== null) {
            $locations[self::URL] = array_merge($locations[self::URL], $custom[self::URL]);
        }

        $byConfig = $this->locationsByConfig($config);

        if (($byConfig[self::DIR] ?? null) !== null) {
            $locations[self::DIR] = array_merge($locations[self::DIR], $byConfig[self::DIR]);
        }

        if (($byConfig[self::URL] ?? null) !== null) {
            $locations[self::URL] = array_merge($locations[self::URL], $byConfig[self::URL]);
        }

        $this->locations = $locations;
    }

    public function resolveUrl(string $location, ?string $subDir = null): ?string
    {
        return $this->resolve($location, self::URL, $subDir);
    }

    public function resolveDir(string $location, ?string $subDir = null): ?string
    {
        return $this->resolve($location, self::DIR, $subDir);
    }

    private function discoverVendorPath(): ?string
    {
        $baseDir = (string) wp_normalize_path(dirname(__DIR__, 2));
        $dependency = 'psr/container/composer.json';
        $dirParts = explode('/', $baseDir);
        $countParts = count($dirParts);
        $vendorName = $countParts > 3 ? array_slice($dirParts, -3, 1)[0] : '';
        $vendorPath = trim($vendorName, '/') !== ''
            ? implode('/', array_slice($dirParts, 0, $countParts - 3)) . sprintf('/%s', $vendorName)
            : null;

        if ($vendorPath !== null && !is_file(sprintf('%s/%s', $vendorPath, $dependency))) {
            $vendorPath = null;
        }

        if ($vendorPath === null && is_file(sprintf('%s/vendor/%s', $baseDir, $dependency))) {
            $vendorPath = sprintf('%s/vendor/', $baseDir);
        }

        return $vendorPath;
    }

    private function resolve(string $location, string $dirOrUrl, ?string $subDir = null): ?string
    {
        $envBase = (string) $this->config->get('WP_APP_' . strtoupper(sprintf('%s_%s', $location, $dirOrUrl)));

        $base = $envBase !== ''
            ? ($dirOrUrl === self::DIR ? wp_normalize_path($envBase) : $envBase)
            : ($this->locations[$dirOrUrl][$location] ?? null);

        if ($base === null && array_key_exists($location, self::CONTENT_LOCATIONS)) {
            $contentBase = $this->resolve(Locations::CONTENT, $dirOrUrl);

            if ($contentBase !== null) {
                $base = $contentBase . self::CONTENT_LOCATIONS[$location];
            }
        }

        if ($base === null) {
            return null;
        }

        $base = trailingslashit((string) $base);

        if ($subDir === null || $subDir === '') {
            return $base;
        }

        if ($dirOrUrl === self::DIR) {
            $subDir = wp_normalize_path($subDir);
        }

        return $base . ltrim((string) $subDir, '\\/');
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function locationsByConfig(EnvConfig $config): array
    {
        $locations = $config->get('LOCATIONS');

        if (!is_array($locations)) {
            return [];
        }

        return $this->parseExtendedDefaults($locations);
    }

    /**
     * @param array<string, mixed> $locations
     * @return array<string, array<string, string>>
     */
    private function parseExtendedDefaults(array $locations): array
    {
        $custom = [self::DIR => [], self::URL => []];
        $customDirs = is_array($locations[self::DIR] ?? null) ? $locations[self::DIR] : [];
        $customUrls = is_array($locations[self::URL] ?? null) ? $locations[self::URL] : [];

        foreach ($customDirs as $key => $customDir) {
            if (is_string($key) && is_string($customDir) && $key !== '' && $customDir !== '') {
                $custom[self::DIR][$key] = trailingslashit(wp_normalize_path($customDir));
            }
        }

        foreach ($customUrls as $key => $customUrl) {
            if (is_string($key) && is_string($customUrl) && $key !== '' && $customUrl !== '') {
                $custom[self::URL][$key] = trailingslashit($customUrl);
            }
        }

        return array_filter($custom);
    }
}
