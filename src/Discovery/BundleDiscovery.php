<?php

declare(strict_types=1);

namespace SymPress\Kernel\Discovery;

use SymPress\Kernel\Bundle\BundleInterface;
use SymPress\Kernel\Bundle\BundleMetadata;
use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Resolver\ActivePackageResolver;
use Composer\InstalledVersions;

final class BundleDiscovery
{
    private const array PACKAGE_PREFIXES = [
        'sympress/',
    ];

    public function __construct(
        private readonly ActivePackageResolver $resolver,
    ) {
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
                    'wordpress-plugin' => 1,
                    'wordpress-theme' => 2,
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
     * @return array<int, string>
     */
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
        foreach (self::PACKAGE_PREFIXES as $prefix) {
            if (str_starts_with($package, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
