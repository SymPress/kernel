<?php

declare(strict_types=1);

namespace SymPress\Kernel\Discovery;

use Composer\InstalledVersions;
use SymPress\Kernel\Bundle\BundleInterface;
use SymPress\Kernel\Bundle\BundleMetadata;
use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Resolver\ActivePackageResolver;

final class BundleDiscovery
{
    /** @var list<string> */
    private array $packagePrefixes;

    /** @param list<string>|array<string> $packagePrefixes Optional package-name prefixes used to narrow discovery. */
    public function __construct(
        private readonly ActivePackageResolver $resolver,
        array $packagePrefixes = [],
    ) {

        $this->packagePrefixes = $this->normalizePackagePrefixes($packagePrefixes);
    }

    public function discover(): BundleRegistry
    {
        $bundles = [];

        foreach ($this->packageNames() as $packageName) {
            $installPath = InstalledVersions::getInstallPath($packageName);

            if (!is_string($installPath) || $installPath === '') {
                continue;
            }

            $composerFile = sprintf('%s/composer.json', $installPath);

            if (!is_file($composerFile)) {
                continue;
            }

            $metadata = $this->composerMetadata($composerFile);
            $kernel = $metadata['extra']['kernel'] ?? null;
            $bundleClass = is_array($kernel) ? (string) ($kernel['bundle'] ?? '') : '';
            $entry = is_array($kernel) ? (string) ($kernel['entry'] ?? '') : '';
            $type = (string) ($metadata['type'] ?? '');

            if ($bundleClass === '' || $entry === '' || $type === '') {
                continue;
            }

            if (!$this->requirementsActive($this->kernelRequirements($kernel))) {
                continue;
            }

            if (!$this->isDiscoverableBundle($type, $entry, $installPath)) {
                continue;
            }

            if (!class_exists($bundleClass)) {
                throw new \RuntimeException(sprintf('Bundle class "%s" is not autoloadable.', $bundleClass));
            }

            $bundle = new $bundleClass();

            if (!$bundle instanceof BundleInterface) {
                throw new \RuntimeException(sprintf('Bundle "%s" must implement %s.', $bundleClass, BundleInterface::class));
            }

            $bundles[] = new BundleMetadata(
                $packageName,
                $type,
                $entry,
                $installPath,
                $composerFile,
                $bundle,
            );
        }

        usort(
            $bundles,
            static function (BundleMetadata $left, BundleMetadata $right): int {
                $priority = [
                    'wordpress-muplugin' => 0,
                    'wordpress-plugin'   => 1,
                    'wordpress-theme'    => 2,
                ];

                $leftPriority = $priority[$left->type()] ?? 99;
                $rightPriority = $priority[$right->type()] ?? 99;

                if ($leftPriority === $rightPriority) {
                    return $left->package() <=> $right->package();
                }

                return $leftPriority <=> $rightPriority;
            },
        );

        $registry = new BundleRegistry();

        foreach ($bundles as $bundle) {
            $registry->add($bundle);
        }

        return $registry;
    }

    private function isDiscoverableBundle(string $type, string $entry, string $installPath): bool
    {
        if ($type === 'library') {
            return true;
        }

        return $this->resolver->isActive($type, $entry, $installPath);
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
            $kernel = $metadata['extra']['kernel'] ?? null;
            $entry = is_array($kernel) ? (string) ($kernel['entry'] ?? '') : '';
            $type = (string) ($metadata['type'] ?? '');

            if ($entry === '' || $type === '') {
                return false;
            }

            if (!$this->isDiscoverableBundle($type, $entry, $installPath)) {
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

    /** @return array<string, mixed> */
    private function composerMetadata(string $composerFile): array
    {
        $contents = file_get_contents($composerFile);

        if (!is_string($contents) || $contents === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<int, string> */
    private function packageNames(): array
    {
        $packages = InstalledVersions::getInstalledPackages();
        $filtered = [];

        foreach ($packages as $package) {
            if (!$this->isKernelPackage($package)) {
                continue;
            }

            $filtered[] = $package;
        }

        sort($filtered);

        return $filtered;
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
            if (!is_string($prefix)) {
                continue;
            }

            $prefix = trim($prefix);

            if ($prefix === '') {
                continue;
            }

            $normalized[] = str_ends_with($prefix, '/') ? $prefix : "{$prefix}/";
        }

        return array_values(array_unique($normalized));
    }
}
