<?php

declare(strict_types=1);

namespace SymPress\Kernel\Kernel;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use SymPress\Kernel\App;
use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Container;
use SymPress\Kernel\SiteConfig;
use SymPress\Kernel\WpContext;
use Symfony\Component\DependencyInjection\Compiler\MergeExtensionConfigurationPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\Filesystem\Filesystem;

final readonly class ContainerCacheManager
{
    public function __construct(
        private string $cacheDir,
        private bool $debug,
        private ContainerResourceFingerprinter $fingerprints,
    ) {
    }

    /** @param array<int, string> $runtimeConfigFiles */
    public function tryUseRuntimeContainer(
        Container $container,
        BundleRegistry $bundles,
        array $runtimeConfigFiles,
    ): bool {

        $metaFile = sprintf('%s/meta.php', $this->cacheDir);
        $lockFile = sprintf('%s/container.lock', $this->cacheDir);
        if (
            !is_file($metaFile) || !is_file($lockFile) || is_link($metaFile) || is_link($lockFile)
            || is_link($this->cacheDir) || (fileperms($this->cacheDir) & 0022) !== 0
        ) {
            return false;
        }
        $lock = fopen($lockFile, 'r');
        if (!is_resource($lock)) {
            return false;
        }
        try {
            if (!flock($lock, LOCK_SH)) {
                return false;
            }
            $metadata = $this->readMetadata($metaFile);
            if (!is_array($metadata) || !$this->fingerprints->discoveryResourcesAreFresh($metadata['config_discovery'] ?? null)) {
                return false;
            }
            $stored = $metadata['runtime_config_files'] ?? null;
            if ($runtimeConfigFiles === [] && is_array($stored)) {
                $runtimeConfigFiles = array_values(array_filter($stored, is_string(...)));
            }
            return $this->useCachedRuntimeContainer(
                $container,
                $this->fingerprints->stringKeyMap($metadata),
                $this->fingerprints->fingerprint($bundles, $runtimeConfigFiles),
            );
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param array<int, string> $configFiles
     * @param list<string> $configDirectories
     */
    public function createRuntimeContainer(
        Container $container,
        BundleRegistry $bundles,
        array $configFiles,
        array $configDirectories = [],
    ): void {

        $filesystem = new Filesystem();
        CachePath::ensureDirectory($this->cacheDir);
        $metaFile = sprintf('%s/meta.php', $this->cacheDir);
        $lockFile = sprintf('%s/container.lock', $this->cacheDir);
        $fingerprint = $this->fingerprints->fingerprint($bundles, $configFiles);
        if (is_link($lockFile)) {
            throw new \RuntimeException('Refusing a symlinked kernel cache lock.');
        }
        $previousMask = umask(0077);
        $lock = fopen($lockFile, 'c+');
        umask($previousMask);
        chmod($lockFile, 0600);

        if (!is_resource($lock)) {
            throw new \RuntimeException(sprintf('Unable to create cache lock "%s".', $lockFile));
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException(sprintf('Unable to lock cache file "%s".', $lockFile));
            }

            clearstatcache(true, $metaFile);

            $metadata = $this->readMetadata($metaFile);

            if (
                is_array($metadata)
                && $this->useCachedRuntimeContainer(
                    $container,
                    $this->fingerprints->stringKeyMap($metadata),
                    $fingerprint,
                )
            ) {
                return;
            }

            $sourceResources = $this->fingerprints->sourceResourceManifest($bundles);
            $sourceFingerprint = $this->fingerprints->sourceResourceFingerprint($sourceResources);
            $inputResources = $this->fingerprints->configResourceManifest($container->builder(), $configFiles);
            $knownResources = is_array($metadata) ? ($metadata['config_resources'] ?? []) : [];
            if (is_array($knownResources)) {
                foreach (array_keys($knownResources) as $path) {
                    if (!is_string($path) || str_starts_with($path, 'exists:')) {
                        continue;
                    }

                    $inputResources[$path] = is_file($path) ? (hash_file('sha256', $path) ?: 'unreadable') : 'missing';
                }
            }
            ksort($inputResources);
            $cacheKey = substr(hash('sha256', "{$fingerprint}|{$sourceFingerprint}|" . serialize($inputResources)), 0, 16);
            $containerFile = sprintf('%s/container_%s.php', $this->cacheDir, $cacheKey);
            clearstatcache(true, $containerFile);

            $class = sprintf('KernelContainer_%s', $cacheKey);
            $runtime = $this->createRuntimeBuilder($container, $class);
            $runtime->compile(true);
            $configResources = $this->fingerprints->configResourceManifest($runtime, $configFiles);
            $dump = (new PhpDumper($runtime))->dump(
                [
                    'class'               => $class,
                    'debug'               => $this->debug,
                    'file'                => $containerFile,
                    'build_time'          => $this->containerBuildTime($runtime),
                    'inline_class_loader' => $this->debug,
                ],
            );

            if (!is_string($dump)) {
                throw new \RuntimeException('The runtime container dumper did not return PHP code.');
            }

            $previousMask = umask(0077);
            try {
                $filesystem->dumpFile($containerFile, $dump);
                chmod($containerFile, 0600);
                $filesystem->dumpFile(
                    $metaFile,
                    sprintf(
                        "<?php\n\nreturn %s;\n",
                        var_export(
                            [
                            'fingerprint'        => $fingerprint,
                            'runtime_config_files' => $configFiles,
                            'config_discovery' => $this->fingerprints->discoveryResourceManifest($configDirectories),
                            'config_resources'   => $configResources,
                            'source_fingerprint' => $sourceFingerprint,
                            'source_resources'   => $sourceResources,
                            'class'              => $class,
                            'file'               => basename($containerFile),
                            ],
                            true,
                        ),
                    ),
                );

                chmod($metaFile, 0600);
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($metaFile, true);
                    opcache_invalidate($containerFile, true);
                }
            } finally {
                umask($previousMask);
            }
            $this->cleanupContainers($containerFile);

            if (!class_exists($class, false)) {
                require $containerFile;
            }

            $container->useRuntimeContainer($this->newRuntimeContainer($class));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<mixed, mixed>|null */
    private function readMetadata(string $file): ?array
    {
        if (!is_file($file) || is_link($file)) {
            return null;
        }
        try {
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($file, true);
            }
            $metadata = require $file;
            return is_array($metadata) ? $metadata : null;
        } catch (\ParseError) {
            return null;
        }
    }

    private function cleanupContainers(string $active): void
    {
        $files = array_values(array_filter(
            glob($this->cacheDir . '/container_*.php') ?: [],
            static fn (string $file): bool => !is_link($file) && preg_match('/^container_[a-f0-9]{16}\.php$/D', basename($file)) === 1,
        ));
        usort($files, static fn (string $left, string $right): int => (int) filemtime($right) <=> (int) filemtime($left));
        foreach (array_slice($files, 3) as $file) {
            if ($file === $active || is_link($file) || preg_match('/^container_[a-f0-9]{16}\.php$/D', basename($file)) !== 1 || filemtime($file) >= time() - 3600) {
                continue;
            }

            unlink($file);
        }
    }

    private function createRuntimeBuilder(Container $container, string $class): ContainerBuilder
    {
        $runtime = new ContainerBuilder();
        $this->copyExtensions($container->builder(), $runtime);
        $runtime->merge($container->builder());
        $cloner = new DefinitionCloner();
        foreach ($runtime->getDefinitions() as $id => $definition) {
            $runtime->setDefinition($id, $cloner->definition($definition));
        }
        foreach ($runtime->getAliases() as $id => $alias) {
            $runtime->setAlias($id, clone $alias);
        }
        $this->copyCompilerPasses($container->builder(), $runtime);
        $runtime->setParameter('kernel.container_class', $class);
        $runtime->getCompilerPassConfig()->setMergePass(
            new MergeExtensionConfigurationPass($this->registeredExtensionAliases($runtime)),
        );
        $this->ensureSynthetic($runtime, Container::CONTAINER_ID, Container::class);
        $this->ensureSynthetic($runtime, Container::CONFIG_ID, SiteConfig::class);
        $this->ensureSynthetic($runtime, Container::CONTEXT_ID, WpContext::class);
        $this->ensureSynthetic($runtime, Container::KERNEL_ID, KernelInterface::class);
        $this->ensureSynthetic($runtime, Container::APP_ID, App::class);

        $runtime->setAlias(Container::class, Container::CONTAINER_ID)->setPublic(true);
        $runtime->setAlias(PsrContainerInterface::class, Container::CONTAINER_ID)->setPublic(true);
        $runtime->setAlias(SiteConfig::class, Container::CONFIG_ID)->setPublic(true);
        $runtime->setAlias(WpContext::class, Container::CONTEXT_ID)->setPublic(true);
        $runtime->setAlias(KernelInterface::class, Container::KERNEL_ID)->setPublic(true);
        $runtime->setAlias(App::class, Container::APP_ID)->setPublic(true);

        return $runtime;
    }

    /** @return list<string> */
    private function registeredExtensionAliases(ContainerBuilder $builder): array
    {
        $aliases = [];

        foreach ($builder->getExtensions() as $extension) {
            $aliases[] = $extension->getAlias();
        }

        return array_values(array_unique($aliases));
    }

    private function copyExtensions(ContainerBuilder $source, ContainerBuilder $target): void
    {
        foreach ($source->getExtensions() as $extension) {
            $target->registerExtension(clone $extension);
        }
    }

    private function copyCompilerPasses(ContainerBuilder $source, ContainerBuilder $target): void
    {
        $sourcePasses = $source->getCompilerPassConfig();
        $targetPasses = $target->getCompilerPassConfig();

        $targetPasses->setBeforeOptimizationPasses($sourcePasses->getBeforeOptimizationPasses());
        $targetPasses->setOptimizationPasses($sourcePasses->getOptimizationPasses());
        $targetPasses->setBeforeRemovingPasses($sourcePasses->getBeforeRemovingPasses());
        $targetPasses->setRemovingPasses($sourcePasses->getRemovingPasses());
        $targetPasses->setAfterRemovingPasses($sourcePasses->getAfterRemovingPasses());
    }

    /** @param array<string, mixed> $metadata */
    private function useCachedRuntimeContainer(
        Container $container,
        array $metadata,
        string $fingerprint,
    ): bool {

        if (
            ($metadata['fingerprint'] ?? null) !== $fingerprint
            || !is_string($metadata['class'] ?? null)
            || !is_string($metadata['file'] ?? null)
            || preg_match('/^KernelContainer_[a-f0-9]{16}$/D', $metadata['class']) !== 1
            || $metadata['file'] !== 'container_' . substr($metadata['class'], strlen('KernelContainer_')) . '.php'
        ) {
            return false;
        }

        if (!$this->fingerprints->configResourcesAreFresh($metadata['config_resources'] ?? null)) {
            return false;
        }

        if (
            $this->fingerprints->shouldValidateCachedSourceResources()
            && !$this->fingerprints->sourceResourcesAreFresh($metadata['source_resources'] ?? null)
        ) {
            return false;
        }

        $cachedContainerFile = sprintf('%s/%s', $this->cacheDir, basename($metadata['file']));

        if (!is_file($cachedContainerFile) || is_link($cachedContainerFile)) {
            return false;
        }

        require_once $cachedContainerFile;
        $class = $metadata['class'];

        if (!class_exists($class, false)) {
            return false;
        }

        $container->useRuntimeContainer($this->newRuntimeContainer($class));

        return true;
    }

    private function containerBuildTime(ContainerBuilder $builder): int
    {
        if ($builder->hasParameter('kernel.container_build_time')) {
            $buildTime = $builder->getParameter('kernel.container_build_time');

            if (is_int($buildTime)) {
                return $buildTime;
            }

            if (is_string($buildTime) && ctype_digit($buildTime)) {
                return (int) $buildTime;
            }
        }

        $sourceDateEpoch = $_SERVER['SOURCE_DATE_EPOCH'] ?? $_ENV['SOURCE_DATE_EPOCH'] ?? null;

        if (is_scalar($sourceDateEpoch) && filter_var($sourceDateEpoch, \FILTER_VALIDATE_INT) !== false) {
            return (int) $sourceDateEpoch;
        }

        return time();
    }

    private function ensureSynthetic(ContainerBuilder $builder, string $id, string $class): void
    {
        if ($builder->hasDefinition($id)) {
            return;
        }

        $builder->setDefinition(
            $id,
            (new Definition($class))
                ->setSynthetic(true)
                ->setPublic(true),
        );
    }

    private function newRuntimeContainer(string $class): PsrContainerInterface
    {
        $instance = new $class();

        if (!$instance instanceof PsrContainerInterface) {
            throw new \RuntimeException(sprintf('Runtime container "%s" is invalid.', $class));
        }

        return $instance;
    }
}
