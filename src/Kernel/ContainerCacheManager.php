<?php

declare(strict_types=1);

namespace SymPress\Kernel\Kernel;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use SymPress\Kernel\App;
use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Container;
use SymPress\Kernel\Hook\HookCompilerPass;
use SymPress\Kernel\Routing\RouteCompilerPass;
use SymPress\Kernel\SiteConfig;
use SymPress\Kernel\WpContext;
use Symfony\Component\Console\DependencyInjection\AddConsoleCommandPass;
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

        if ($this->fingerprints->tracksSourceChanges()) {
            return false;
        }

        $metaFile = sprintf('%s/meta.php', $this->cacheDir);

        if (!is_file($metaFile)) {
            return false;
        }

        $metadata = require $metaFile;

        if (!is_array($metadata)) {
            return false;
        }

        return $this->useCachedRuntimeContainer(
            $container,
            $this->fingerprints->stringKeyMap($metadata),
            $this->fingerprints->fingerprint($bundles, $runtimeConfigFiles),
        );
    }

    /** @param array<int, string> $configFiles */
    public function createRuntimeContainer(
        Container $container,
        BundleRegistry $bundles,
        array $configFiles,
    ): void {

        $filesystem = new Filesystem();
        $filesystem->mkdir($this->cacheDir);
        $metaFile = sprintf('%s/meta.php', $this->cacheDir);
        $lockFile = sprintf('%s/container.lock', $this->cacheDir);
        $fingerprint = $this->fingerprints->fingerprint($bundles, $configFiles);
        $lock = fopen($lockFile, 'c+');

        if (!is_resource($lock)) {
            throw new \RuntimeException(sprintf('Unable to create cache lock "%s".', $lockFile));
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException(sprintf('Unable to lock cache file "%s".', $lockFile));
            }

            clearstatcache(true, $metaFile);

            $metadata = is_file($metaFile) ? require $metaFile : null;

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
            $cacheKey = substr(hash('sha256', "{$fingerprint}|{$sourceFingerprint}"), 0, 16);
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

            $filesystem->dumpFile($containerFile, $dump);
            $filesystem->dumpFile(
                $metaFile,
                sprintf(
                    "<?php\n\nreturn %s;\n",
                    var_export(
                        [
                            'fingerprint'        => $fingerprint,
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

            if (!class_exists($class, false)) {
                require $containerFile;
            }

            $container->useRuntimeContainer($this->newRuntimeContainer($class));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function createRuntimeBuilder(Container $container, string $class): ContainerBuilder
    {
        $runtime = new ContainerBuilder();
        $this->copyExtensions($container->builder(), $runtime);
        $runtime->merge($container->builder());
        $this->copyCompilerPasses($container->builder(), $runtime);
        $runtime->setParameter('kernel.container_class', $class);
        $runtime->getCompilerPassConfig()->setMergePass(
            new MergeExtensionConfigurationPass($this->registeredExtensionAliases($runtime)),
        );
        $runtime->addCompilerPass(new HookCompilerPass());
        $runtime->addCompilerPass(new RouteCompilerPass());
        $runtime->addCompilerPass(new AddConsoleCommandPass());
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
            $target->registerExtension($extension);
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

        if (!is_file($cachedContainerFile)) {
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
