<?php

declare(strict_types=1);

namespace SymPress\Kernel\Package;

use SymPress\Kernel\Resolver\ActivePackageResolver;
use Composer\InstalledVersions;

final class PackageDiscovery
{
    private const array PACKAGE_PREFIXES = [
        'sympress/',
    ];

    public function __construct(
        private readonly ActivePackageResolver $resolver,
    ) {
    }

    /**
     * @return list<PackageMetadata>
     */
    public function all(): array
    {
        $packages = [];

        foreach ($this->packageNames() as $packageName) {
            $package = $this->package($packageName);

            if (!$package instanceof PackageMetadata) {
                continue;
            }

            $packages[] = $package;
        }

        usort($packages, $this->sort(...));

        return $packages;
    }

    public function find(string $packageName): ?PackageMetadata
    {
        foreach ($this->all() as $package) {
            if ($package->package() === $packageName) {
                return $package;
            }
        }

        return null;
    }

    private function package(string $packageName): ?PackageMetadata
    {
        $installPath = InstalledVersions::getInstallPath($packageName);

        if (!is_string($installPath) || $installPath === '') {
            return null;
        }

        $composerFile = sprintf('%s/composer.json', rtrim($installPath, '/'));

        if (!is_file($composerFile)) {
            return null;
        }

        $metadata = $this->composerMetadata($composerFile);
        $kernel = $metadata['extra']['kernel'] ?? null;
        $bundleClass = is_array($kernel) ? (string) ($kernel['bundle'] ?? '') : '';
        $entry = is_array($kernel) ? (string) ($kernel['entry'] ?? '') : '';
        $type = (string) ($metadata['type'] ?? '');

        if ($bundleClass === '' || $entry === '' || $type === '') {
            return null;
        }

        $display = $this->displayData($metadata, $type, $entry, $installPath);

        return new PackageMetadata(
            $packageName,
            $type,
            $entry,
            $installPath,
            $composerFile,
            $bundleClass,
            $display['name'],
            $display['description'],
            $display['version'],
            $this->resolver->isActive($type, $entry, $installPath),
        );
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array{name: string, description: string, version: string}
     */
    private function displayData(
        array $metadata,
        string $type,
        string $entry,
        string $installPath,
    ): array {
        $data = [
            'name' => $this->humanName((string) ($metadata['name'] ?? '')),
            'description' => (string) ($metadata['description'] ?? ''),
            'version' => (string) ($metadata['version'] ?? ''),
        ];

        if ($type === 'wordpress-theme') {
            return $this->themeDisplayData($entry, $data);
        }

        return $this->pluginDisplayData($entry, $installPath, $data);
    }

    /**
     * @param array{name: string, description: string, version: string} $fallback
     * @return array{name: string, description: string, version: string}
     */
    private function pluginDisplayData(string $entry, string $installPath, array $fallback): array
    {
        $pluginFile = sprintf(
            '%s/%s',
            rtrim($installPath, '/'),
            $this->entryPathWithinInstall($entry),
        );

        $this->loadPluginAdminFunctions();

        if (!function_exists('get_plugin_data') || !is_file($pluginFile)) {
            return $fallback;
        }

        $pluginData = get_plugin_data($pluginFile, false, false);

        return [
            'name' => $this->nonEmptyString($pluginData['Name'] ?? null, $fallback['name']),
            'description' => $this->nonEmptyString(
                $pluginData['Description'] ?? null,
                $fallback['description'],
            ),
            'version' => $this->nonEmptyString(
                $pluginData['Version'] ?? null,
                $fallback['version'],
            ),
        ];
    }

    /**
     * @param array{name: string, description: string, version: string} $fallback
     * @return array{name: string, description: string, version: string}
     */
    private function themeDisplayData(string $entry, array $fallback): array
    {
        if (!function_exists('wp_get_theme')) {
            return $fallback;
        }

        $theme = wp_get_theme($entry);

        if (!$theme->exists()) {
            return $fallback;
        }

        return [
            'name' => $this->nonEmptyString($theme->get('Name'), $fallback['name']),
            'description' => $this->nonEmptyString(
                $theme->get('Description'),
                $fallback['description'],
            ),
            'version' => $this->nonEmptyString($theme->get('Version'), $fallback['version']),
        ];
    }

    private function entryPathWithinInstall(string $entry): string
    {
        $parts = array_values(
            array_filter(
                explode('/', str_replace('\\', '/', $entry)),
                static fn (string $part): bool => $part !== '',
            ),
        );

        if (count($parts) > 1) {
            array_shift($parts);
        }

        return implode('/', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function composerMetadata(string $composerFile): array
    {
        $contents = file_get_contents($composerFile);

        if (!is_string($contents) || $contents === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<string>
     */
    private function packageNames(): array
    {
        $packages = [];

        foreach (InstalledVersions::getInstalledPackages() as $package) {
            if ($this->isKernelPackage($package)) {
                $packages[] = $package;
            }
        }

        sort($packages);

        return $packages;
    }

    private function sort(PackageMetadata $left, PackageMetadata $right): int
    {
        $priority = [
            'wordpress-muplugin' => 0,
            'wordpress-plugin' => 1,
            'wordpress-theme' => 2,
        ];

        $leftPriority = $priority[$left->type()] ?? 99;
        $rightPriority = $priority[$right->type()] ?? 99;

        if ($leftPriority === $rightPriority) {
            return strcasecmp($left->name(), $right->name());
        }

        return $leftPriority <=> $rightPriority;
    }

    private function humanName(string $packageName): string
    {
        $name = preg_replace('/^sympress\//', '', $packageName);
        $name = str_replace(['-', '_'], ' ', is_string($name) ? $name : $packageName);

        return ucwords($name);
    }

    private function isKernelPackage(string $package): bool
    {
        foreach (self::PACKAGE_PREFIXES as $prefix) {
            if (str_starts_with($package, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function nonEmptyString(mixed $value, string $fallback): string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return $fallback;
    }

    private function loadPluginAdminFunctions(): void
    {
        if (function_exists('get_plugin_data') || !defined('ABSPATH')) {
            return;
        }

        $file = ABSPATH . 'wp-admin/includes/plugin.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
}
