<?php

declare(strict_types=1);

namespace SymPress\Kernel\Package;

use Composer\InstalledVersions;
use SymPress\Kernel\Resolver\ActivePackageResolver;

final class PackageDiscovery
{
    /** @var list<string> */
    private array $packagePrefixes;

    /** @var list<PackageMetadata>|null */
    private ?array $packages = null;

    /** @var array<string, PackageMetadata>|null */
    private ?array $packagesByName = null;

    /** @var list<string>|null */
    private ?array $packageNames = null;

    /** @var array<string, array<string, mixed>> */
    private array $metadata = [];

    /** @param list<string>|array<string> $packagePrefixes Optional package-name prefixes used to narrow discovery. */
    public function __construct(
        private readonly ActivePackageResolver $resolver,
        array $packagePrefixes = [],
    ) {

        $this->packagePrefixes = $this->normalizePackagePrefixes($packagePrefixes);
    }

    /** @return list<PackageMetadata> */
    public function all(): array
    {
        if ($this->packages !== null) {
            return $this->packages;
        }

        $packages = [];

        foreach ($this->packageNames() as $packageName) {
            $package = $this->package($packageName);

            if (!$package instanceof PackageMetadata) {
                continue;
            }

            $packages[] = $package;
        }

        usort($packages, $this->sort(...));

        $this->packages = $packages;
        $this->packagesByName = null;

        return $this->packages;
    }

    public function find(string $packageName): ?PackageMetadata
    {
        return $this->packagesByName()[$packageName] ?? null;
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
        $kernel = $this->kernelMetadata($metadata);
        $bundleClass = $this->stringValue($kernel['bundle'] ?? null);
        $entry = $this->stringValue($kernel['entry'] ?? null);
        $type = $this->stringValue($metadata['type'] ?? null);

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
            $this->isDiscoverablePackage($type, $entry, $installPath)
                && $this->requirementsActive($this->kernelRequirements($kernel)),
        );
    }

    private function isDiscoverablePackage(string $type, string $entry, string $installPath): bool
    {
        if ($type === 'library') {
            return true;
        }

        return $this->resolver->isActive($type, $entry, $installPath);
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
            'name'        => $this->humanName($this->stringValue($metadata['name'] ?? null)),
            'description' => $this->stringValue($metadata['description'] ?? null),
            'version'     => $this->stringValue($metadata['version'] ?? null),
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
            'name'        => $this->nonEmptyString($pluginData['Name'], $fallback['name']),
            'description' => $this->nonEmptyString(
                $pluginData['Description'],
                $fallback['description'],
            ),
            'version'     => $this->nonEmptyString(
                $pluginData['Version'],
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
            'name'        => $this->nonEmptyString($theme->get('Name'), $fallback['name']),
            'description' => $this->nonEmptyString(
                $theme->get('Description'),
                $fallback['description'],
            ),
            'version'     => $this->nonEmptyString($theme->get('Version'), $fallback['version']),
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

    /** @return array<string, mixed> */
    private function composerMetadata(string $composerFile): array
    {
        if (isset($this->metadata[$composerFile])) {
            return $this->metadata[$composerFile];
        }

        $contents = file_get_contents($composerFile);

        if (!is_string($contents) || $contents === '') {
            $this->metadata[$composerFile] = [];

            return $this->metadata[$composerFile];
        }

        $decoded = json_decode($contents, true);

        $this->metadata[$composerFile] = is_array($decoded) ? $this->stringKeyMap($decoded) : [];

        return $this->metadata[$composerFile];
    }

    /** @return list<string> */
    private function packageNames(): array
    {
        if ($this->packageNames !== null) {
            return $this->packageNames;
        }

        $packages = [];

        foreach (InstalledVersions::getInstalledPackages() as $package) {
            if (!$this->isKernelPackage($package)) {
                continue;
            }

            $packages[] = $package;
        }

        sort($packages);

        $this->packageNames = $packages;

        return $this->packageNames;
    }

    /** @return array<string, PackageMetadata> */
    private function packagesByName(): array
    {
        if ($this->packagesByName !== null) {
            return $this->packagesByName;
        }

        $index = [];

        foreach ($this->all() as $package) {
            $index[$package->package()] = $package;
        }

        $this->packagesByName = $index;

        return $this->packagesByName;
    }

    private function sort(PackageMetadata $left, PackageMetadata $right): int
    {
        $priority = [
            'wordpress-muplugin' => 0,
            'wordpress-plugin'   => 1,
            'wordpress-theme'    => 2,
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
        $name = preg_replace('#^[^/]+/#', '', $packageName);
        $name = str_replace(['-', '_'], ' ', is_string($name) ? $name : $packageName);

        return ucwords($name);
    }

    private function isKernelPackage(string $package): bool
    {
        if ($this->packagePrefixes === []) {
            return true;
        }

        foreach ($this->packagePrefixes as $prefix) {
            if (str_starts_with($package, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string> $packagePrefixes
     * @return list<string>
     */
    private function normalizePackagePrefixes(array $packagePrefixes): array
    {
        $normalized = [];

        foreach ($packagePrefixes as $prefix) {
            $prefix = trim($prefix);

            if ($prefix === '') {
                continue;
            }

            $normalized[] = str_ends_with($prefix, '/') ? $prefix : "{$prefix}/";
        }

        return array_values(array_unique($normalized));
    }

    /** @param list<string> $requirements */
    private function requirementsActive(array $requirements): bool
    {
        foreach ($requirements as $packageName) {
            $installPath = InstalledVersions::getInstallPath($packageName);

            if (!is_string($installPath) || $installPath === '') {
                return false;
            }

            $composerFile = sprintf('%s/composer.json', $installPath);

            if (!is_file($composerFile)) {
                return false;
            }

            $metadata = $this->composerMetadata($composerFile);
            $kernel = $this->kernelMetadata($metadata);
            $entry = $this->stringValue($kernel['entry'] ?? null);
            $type = $this->stringValue($metadata['type'] ?? null);

            if ($entry === '' || $type === '') {
                return false;
            }

            if (!$this->isDiscoverablePackage($type, $entry, $installPath)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function kernelRequirements(mixed $kernel): array
    {
        if (!is_array($kernel)) {
            return [];
        }

        $requires = $kernel['requires'] ?? [];

        if (is_string($requires) && $requires !== '') {
            return [$requires];
        }

        if (!is_array($requires)) {
            return [];
        }

        return array_values(
            array_filter(
                $requires,
                static fn (mixed $package): bool => is_string($package) && $package !== '',
            ),
        );
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

        if (!is_file($file)) {
            return;
        }

        require_once $file;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function kernelMetadata(array $metadata): array
    {
        $extra = $metadata['extra'] ?? null;

        if (!is_array($extra)) {
            return [];
        }

        $kernel = $extra['kernel'] ?? null;

        return is_array($kernel) ? $this->stringKeyMap($kernel) : [];
    }

    private function stringValue(mixed $value): string
    {
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @param array<mixed, mixed> $values
     * @return array<string, mixed>
     */
    private function stringKeyMap(array $values): array
    {
        $map = [];

        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $map[$key] = $value;
        }

        return $map;
    }
}
