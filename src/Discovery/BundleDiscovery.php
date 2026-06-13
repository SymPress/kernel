<?php

declare(strict_types=1);

namespace SymPress\Kernel\Discovery;

use Composer\InstalledVersions;
use SymPress\Kernel\Bundle\BundleInterface;
use SymPress\Kernel\Bundle\BundleMetadata;
use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Resolver\ActivePackageResolver;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;

final class BundleDiscovery
{
    private const array ENVIRONMENT_ALIASES = [
        'dev'            => 'development',
        'develop'        => 'development',
        'development'    => 'development',
        'local'          => 'local',
        'pre-prod'       => 'staging',
        'pre-production' => 'staging',
        'preprod'        => 'staging',
        'prod'           => 'production',
        'production'     => 'production',
        'stage'          => 'staging',
        'staging'        => 'staging',
        'test'           => 'test',
        'uat'            => 'staging',
    ];

    /** @var list<string> */
    private array $packagePrefixes;

    /** @param list<string>|array<string> $packagePrefixes Optional package-name prefixes used to narrow discovery. */
    public function __construct(
        private readonly ActivePackageResolver $resolver,
        array $packagePrefixes = [],
        private readonly ?string $projectDir = null,
        private readonly ?string $environment = null,
    ) {

        $this->packagePrefixes = $this->normalizePackagePrefixes($packagePrefixes);
    }

    public function discover(): BundleRegistry
    {
        $bundles = [];
        $seen = [];

        foreach ($this->composerBundles() as $bundle) {
            $bundles[] = $bundle;
            $seen[$bundle->bundle()->id()] = true;
        }

        foreach ($this->configuredBundles() as $bundle) {
            if (isset($seen[$bundle->bundle()->id()])) {
                continue;
            }

            $bundles[] = $bundle;
            $seen[$bundle->bundle()->id()] = true;
        }

        foreach ($this->filteredBundles() as $bundle) {
            if (isset($seen[$bundle->bundle()->id()])) {
                continue;
            }

            $bundles[] = $bundle;
            $seen[$bundle->bundle()->id()] = true;
        }

        usort($bundles, $this->sortBundles(...));

        $registry = new BundleRegistry();
        $metadataByClass = [];
        $registered = [];

        foreach ($bundles as $bundle) {
            $metadataByClass[$bundle->bundle()::class] = $bundle;
        }

        foreach ($bundles as $bundle) {
            $this->addBundleWithRequirements($bundle, $metadataByClass, $registry, $registered);
        }

        return $registry;
    }

    /** @return list<BundleMetadata> */
    private function composerBundles(): array
    {
        $bundles = [];

        foreach ($this->packageNames() as $packageName) {
            $bundle = $this->composerBundle($packageName);

            if (!$bundle instanceof BundleMetadata) {
                continue;
            }

            $bundles[] = $bundle;
        }

        return $bundles;
    }

    private function composerBundle(string $packageName): ?BundleMetadata
    {
        $installPath = InstalledVersions::getInstallPath($packageName);

        if (!is_string($installPath) || $installPath === '') {
            return null;
        }

        $composerFile = sprintf('%s/composer.json', $installPath);

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

        if (!$this->requirementsActive($this->kernelRequirements($kernel))) {
            return null;
        }

        if (!$this->isDiscoverableBundle($type, $entry, $installPath)) {
            return null;
        }

        return $this->createMetadata($bundleClass, $packageName, $type, $entry, $installPath, $composerFile);
    }

    /** @return list<BundleMetadata> */
    private function configuredBundles(): array
    {
        $configFile = $this->projectDir !== null
            ? sprintf('%s/config/bundles.php', rtrim($this->projectDir, '/'))
            : null;

        if ($configFile === null || !is_file($configFile)) {
            return [];
        }

        $configuration = require $configFile;

        if (!is_array($configuration)) {
            return [];
        }

        return $this->bundlesFromConfiguration($configuration, $configFile);
    }

    /** @return list<BundleMetadata> */
    private function filteredBundles(): array
    {
        if (!function_exists('apply_filters')) {
            return [];
        }

        $configuration = apply_filters('symfony_register_bundles', []);

        if (!is_array($configuration)) {
            return [];
        }

        return $this->bundlesFromConfiguration($configuration, 'symfony_register_bundles');
    }

    /**
     * @param array<mixed, mixed> $configuration
     * @return list<BundleMetadata>
     */
    private function bundlesFromConfiguration(array $configuration, string $source): array
    {
        $bundles = [];

        foreach ($configuration as $bundleClassOrIndex => $optionsOrBundle) {
            $bundle = $this->configuredBundle($bundleClassOrIndex, $optionsOrBundle, $source);

            if (!$bundle instanceof BundleMetadata) {
                continue;
            }

            $bundles[] = $bundle;
        }

        return $bundles;
    }

    private function configuredBundle(mixed $key, mixed $value, string $source): ?BundleMetadata
    {
        if ($value instanceof BundleInterface) {
            return $this->createConfiguredMetadata($value, $source);
        }

        $bundleClass = is_string($value) ? $value : (is_string($key) ? $key : '');
        $environments = is_array($value) ? $value : ['all' => true];

        if ($bundleClass === '' || !$this->shouldLoadConfiguredBundle($environments)) {
            return null;
        }

        return $this->createMetadata(
            $bundleClass,
            $bundleClass,
            'library',
            '',
            $this->bundlePath($bundleClass),
            $source,
        );
    }

    private function createConfiguredMetadata(BundleInterface $bundle, string $source): BundleMetadata
    {
        return new BundleMetadata(
            $bundle->id(),
            'library',
            '',
            $bundle->path(),
            $source,
            $bundle,
        );
    }

    private function createMetadata(
        string $bundleClass,
        string $packageName,
        string $type,
        string $entry,
        string $installPath,
        string $composerFile,
    ): BundleMetadata {

        if (!class_exists($bundleClass)) {
            throw new \RuntimeException(sprintf('Bundle class "%s" is not autoloadable.', $bundleClass));
        }

        $bundle = new $bundleClass();

        if (!$bundle instanceof BundleInterface) {
            throw new \RuntimeException(
                sprintf('Bundle "%s" must implement %s.', $bundleClass, BundleInterface::class),
            );
        }

        return new BundleMetadata(
            $packageName,
            $type,
            $entry,
            $installPath,
            $composerFile,
            $bundle,
        );
    }

    /**
     * @param array<class-string, BundleMetadata> $metadataByClass
     * @param array<class-string, true>           $registered
     * @param array<class-string, true>           $visiting
     */
    private function addBundleWithRequirements(
        BundleMetadata $metadata,
        array $metadataByClass,
        BundleRegistry $registry,
        array &$registered,
        array $visiting = [],
    ): void {

        $bundleClass = $metadata->bundle()::class;

        if (isset($registered[$bundleClass])) {
            return;
        }

        if (isset($visiting[$bundleClass])) {
            return;
        }

        $visiting[$bundleClass] = true;

        foreach ($this->requiredBundleClasses($bundleClass) as $requiredClass) {
            if (isset($registered[$requiredClass])) {
                continue;
            }

            $requiredMetadata = $metadataByClass[$requiredClass] ?? $this->requiredBundleMetadata($requiredClass);

            if (!$requiredMetadata instanceof BundleMetadata) {
                continue;
            }

            $this->addBundleWithRequirements($requiredMetadata, $metadataByClass, $registry, $registered, $visiting);
        }

        $registry->add($metadata);
        $registered[$bundleClass] = true;
    }

    /** @param class-string $bundleClass */
    private function requiredBundleMetadata(string $bundleClass): ?BundleMetadata
    {
        if (!class_exists($bundleClass)) {
            return null;
        }

        return $this->createMetadata(
            $bundleClass,
            $bundleClass,
            'library',
            '',
            $this->bundlePath($bundleClass),
            sprintf('required-bundle:%s', $bundleClass),
        );
    }

    /**
     * @param class-string $bundleClass
     * @return list<class-string>
     */
    private function requiredBundleClasses(string $bundleClass): array
    {
        if (!class_exists($bundleClass)) {
            return [];
        }

        $classes = [];
        $reflection = new \ReflectionClass($bundleClass);

        foreach ($reflection->getAttributes(RequiredBundle::class) as $attribute) {
            $required = $attribute->newInstance();
            $requiredClass = $required->class;

            if (!class_exists($requiredClass)) {
                if ($required->ignoreOnInvalid) {
                    continue;
                }

                throw new \RuntimeException(
                    sprintf(
                        'Required bundle class "%s" declared by "%s" is not autoloadable.',
                        $requiredClass,
                        $bundleClass,
                    ),
                );
            }

            $classes[] = $requiredClass;
        }

        return array_values(array_unique($classes));
    }

    private function isDiscoverableBundle(string $type, string $entry, string $installPath): bool
    {
        if ($type === 'library') {
            return true;
        }

        return $this->resolver->isActive($type, $entry, $installPath);
    }

    /** @param array<string, mixed> $environments */
    private function shouldLoadConfiguredBundle(array $environments): bool
    {
        if (($environments['all'] ?? false) === true) {
            return true;
        }

        foreach ($this->environmentNames() as $environment) {
            if (($environments[$environment] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function environmentNames(): array
    {
        $environment = strtolower((string) ($this->environment ?? 'production'));
        $normalized = self::ENVIRONMENT_ALIASES[$environment] ?? $environment;
        $names = [$environment, $normalized];

        foreach (self::ENVIRONMENT_ALIASES as $alias => $target) {
            if ($target !== $normalized) {
                continue;
            }

            $names[] = $alias;
        }

        return array_values(array_unique($names));
    }

    private function bundlePath(string $bundleClass): string
    {
        if (!class_exists($bundleClass)) {
            return $this->projectDir ?? '';
        }

        $reflection = new \ReflectionClass($bundleClass);
        $file = $reflection->getFileName();

        return is_string($file) ? dirname($file, 2) : ($this->projectDir ?? '');
    }

    private function sortBundles(BundleMetadata $left, BundleMetadata $right): int
    {
        $priority = [
            'wordpress-muplugin' => 0,
            'wordpress-plugin'   => 1,
            'wordpress-theme'    => 2,
            'library'            => 3,
        ];

        $leftPriority = $priority[$left->type()] ?? 99;
        $rightPriority = $priority[$right->type()] ?? 99;

        if ($leftPriority === $rightPriority) {
            return $left->package() <=> $right->package();
        }

        return $leftPriority <=> $rightPriority;
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
