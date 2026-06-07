<?php

declare(strict_types=1);

namespace SymPress\Kernel\Kernel;

use SymPress\Kernel\Attribute\AsHook;
use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Console\ConsoleApplicationFactory;
use SymPress\Kernel\Console\WpCliConsoleBridge;
use SymPress\Kernel\Container;
use SymPress\Kernel\Discovery\BundleDiscovery;
use SymPress\Kernel\EnvConfig;
use SymPress\Kernel\Hook\HookCompilerPass;
use SymPress\Kernel\Hook\HookLoader;
use SymPress\Kernel\Resolver\ActivePackageResolver;
use SymPress\Kernel\SiteConfig;
use SymPress\Kernel\WpContext;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\DependencyInjection\AddConsoleCommandPass;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Filesystem\Filesystem;

abstract class AbstractKernel implements KernelInterface
{
    protected readonly SiteConfig $config;
    protected readonly WpContext $context;
    protected bool $booted = false;

    /**
     * @var array<int, true>
     */
    private array $preparedBuilders = [];

    public function __construct(
        protected readonly string $projectDir,
        ?string $environment = null,
        ?bool $debug = null,
        ?SiteConfig $config = null,
        ?WpContext $context = null,
    ) {
        $this->config = $config ?? new EnvConfig();
        $this->context = $context ?? WpContext::determine();
        $this->environment = $environment ?? $this->config->env();
        $this->debug = $debug ?? defined('WP_DEBUG') && WP_DEBUG;
    }

    protected string $environment;
    protected bool $debug;

    public function getProjectDir(): string
    {
        return $this->projectDir;
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    public function getCacheDir(): string
    {
        return sprintf('%s/var/cache/%s/kernel', $this->projectDir, $this->environment);
    }

    public function createContainer(): Container
    {
        $container = new Container($this->config, $this->context);
        $container->setKernel($this);

        return $container;
    }

    public function discoverBundles(): BundleRegistry
    {
        return (new BundleDiscovery(new ActivePackageResolver()))->discover();
    }

    public function configureContainer(
        ContainerBuilder $builder,
        Container $container,
        BundleRegistry $bundles,
    ): array {
        $this->prepareBuilder($builder);
        $builder->setParameter('kernel.project_dir', $this->projectDir);
        $builder->setParameter('kernel.environment', $this->environment);
        $builder->setParameter('kernel.debug', $this->debug);
        $builder->setParameter('kernel.cache_dir', $this->getCacheDir());
        $builder->setParameter('kernel.logs_dir', sprintf('%s/var/log', $this->projectDir));

        foreach ($bundles->all() as $bundle) {
            $bundle->bundle()->build($builder);
        }

        $loaded = [];

        foreach ($this->configDirectories($bundles) as $configDir) {
            foreach ($this->resolveConfigFiles($configDir) as $file) {
                $this->loadConfigFile($builder, $file);
                $loaded[] = $file;
            }
        }

        $container->hydrateBuilder();

        return array_values(array_unique($loaded));
    }

    public function build(ContainerBuilder $builder): void
    {
    }

    public function tryUseRuntimeContainer(Container $container, BundleRegistry $bundles): bool
    {
        if ($this->tracksSourceChanges()) {
            return false;
        }

        $cacheDir = $this->getCacheDir();
        $metaFile = sprintf('%s/meta.php', $cacheDir);

        if (!is_file($metaFile)) {
            return false;
        }

        $metadata = require $metaFile;

        if (!is_array($metadata)) {
            return false;
        }

        return $this->useCachedRuntimeContainer(
            $container,
            $cacheDir,
            $metadata,
            $this->fingerprint($bundles, $this->runtimeConfigFiles($bundles)),
        );
    }

    public function createRuntimeContainer(
        Container $container,
        BundleRegistry $bundles,
        array $configFiles,
    ): void {
        $cacheDir = $this->getCacheDir();
        $filesystem = new Filesystem();
        $filesystem->mkdir($cacheDir);
        $metaFile = sprintf('%s/meta.php', $cacheDir);
        $lockFile = sprintf('%s/container.lock', $cacheDir);
        $fingerprint = $this->fingerprint($bundles, $configFiles);
        $cacheKey = substr(hash('sha256', $fingerprint), 0, 16);
        $containerFile = sprintf('%s/container_%s.php', $cacheDir, $cacheKey);
        $lock = fopen($lockFile, 'c+');

        if (!is_resource($lock)) {
            throw new \RuntimeException(sprintf('Unable to create cache lock "%s".', $lockFile));
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException(sprintf('Unable to lock cache file "%s".', $lockFile));
            }

            clearstatcache(true, $containerFile);
            clearstatcache(true, $metaFile);

            $metadata = is_file($metaFile) ? require $metaFile : null;

            if (
                is_array($metadata)
                && $this->useCachedRuntimeContainer($container, $cacheDir, $metadata, $fingerprint)
            ) {
                return;
            }

            $runtime = $this->createRuntimeBuilder($container);
            $runtime->compile(true);
            $class = sprintf('KernelContainer_%s', $cacheKey);
            $dumper = new PhpDumper($runtime);
            $filesystem->dumpFile($containerFile, $dumper->dump(['class' => $class]));
            $filesystem->dumpFile(
                $metaFile,
                sprintf(
                    "<?php\n\nreturn %s;\n",
                    var_export(
                        [
                            'fingerprint' => $fingerprint,
                            'class' => $class,
                            'file' => basename($containerFile),
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

    public function boot(Container $container, BundleRegistry $bundles): void
    {
        $this->booted = true;
    }

    public function shutdown(): void
    {
        $this->booted = false;
    }

    /**
     * @return array<int, string>
     */
    private function configDirectories(BundleRegistry $bundles): array
    {
        $directories = [];
        $libraryDir = dirname(__DIR__, 2) . '/config';
        $siteDir = sprintf('%s/config', $this->projectDir);

        if (is_dir($libraryDir)) {
            $directories[] = $libraryDir;
        }

        foreach ($bundles->configDirectories() as $configDir) {
            $directories[] = $configDir;
        }

        if (is_dir($siteDir)) {
            $directories[] = $siteDir;
        }

        return array_values(array_unique($directories));
    }

    /**
     * @return array<int, string>
     */
    private function resolveConfigFiles(string $configDir): array
    {
        $files = [];

        foreach ($this->patterns($configDir) as $pattern) {
            $matches = glob($pattern, GLOB_BRACE) ?: [];
            sort($matches);
            $files = [...$files, ...$matches];
        }

        return $files;
    }

    /**
     * @return array<int, string>
     */
    private function runtimeConfigFiles(BundleRegistry $bundles): array
    {
        $files = [];

        foreach ($this->configDirectories($bundles) as $configDir) {
            $files = [...$files, ...$this->resolveConfigFiles($configDir)];
        }

        return array_values(array_unique($files));
    }

    /**
     * @return array<int, string>
     */
    private function patterns(string $configDir): array
    {
        $env = $this->environment;

        return [
            sprintf('%s/packages/*.{php,yaml,yml}', $configDir),
            sprintf('%s/packages/%s/*.{php,yaml,yml}', $configDir, $env),
            sprintf('%s/services.{php,yaml,yml}', $configDir),
            sprintf('%s/services_%s.{php,yaml,yml}', $configDir, $env),
            sprintf('%s/wordpress.{php,yaml,yml}', $configDir),
            sprintf('%s/wordpress_%s.{php,yaml,yml}', $configDir, $env),
        ];
    }

    private function loadConfigFile(ContainerBuilder $builder, string $file): void
    {
        $locator = new FileLocator(dirname($file));
        $basename = basename($file);
        $extension = pathinfo($basename, PATHINFO_EXTENSION);

        if ($extension === 'php') {
            (new PhpFileLoader($builder, $locator, $this->environment))->load($basename);

            return;
        }

        if (in_array($extension, ['yaml', 'yml'], true)) {
            (new YamlFileLoader($builder, $locator, $this->environment))->load($basename);

            return;
        }

        throw new \RuntimeException(sprintf('Unsupported config file "%s".', $file));
    }

    /**
     * @param array<int, string> $configFiles
     */
    private function fingerprint(BundleRegistry $bundles, array $configFiles): string
    {
        sort($configFiles);

        $parts = [
            $this->projectDir,
            $this->environment,
            (string) (int) $this->debug,
            $this->deploymentFingerprint(),
            $this->kernelFingerprint(),
            ...$bundles->fingerprintParts($this->tracksSourceChanges()),
        ];

        foreach ($configFiles as $file) {
            $parts[] = sprintf(
                '%s:%s',
                $file,
                is_file($file) ? $this->fileFingerprint($file) : 'missing',
            );
        }

        return hash('sha256', implode('|', $parts));
    }

    private function createRuntimeBuilder(Container $container): ContainerBuilder
    {
        $runtime = new ContainerBuilder();
        $this->copyExtensions($container->builder(), $runtime);
        $runtime->merge($container->builder());
        $this->copyCompilerPasses($container->builder(), $runtime);
        $runtime->addCompilerPass(new HookCompilerPass());
        $runtime->addCompilerPass(new AddConsoleCommandPass());
        $this->ensureSynthetic($runtime, Container::CONTAINER_ID, Container::class);
        $this->ensureSynthetic($runtime, Container::CONFIG_ID, SiteConfig::class);
        $this->ensureSynthetic($runtime, Container::CONTEXT_ID, WpContext::class);
        $this->ensureSynthetic($runtime, Container::KERNEL_ID, KernelInterface::class);
        $this->ensureSynthetic($runtime, Container::APP_ID, \SymPress\Kernel\App::class);

        $runtime->setAlias(Container::class, Container::CONTAINER_ID)->setPublic(true);
        $runtime->setAlias(PsrContainerInterface::class, Container::CONTAINER_ID)->setPublic(true);
        $runtime->setAlias(SiteConfig::class, Container::CONFIG_ID)->setPublic(true);
        $runtime->setAlias(WpContext::class, Container::CONTEXT_ID)->setPublic(true);
        $runtime->setAlias(KernelInterface::class, Container::KERNEL_ID)->setPublic(true);
        $runtime->setAlias(\SymPress\Kernel\App::class, Container::APP_ID)->setPublic(true);

        return $runtime;
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

    /**
     * @param array<string, mixed> $metadata
     */
    private function useCachedRuntimeContainer(
        Container $container,
        string $cacheDir,
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

        $cachedContainerFile = sprintf('%s/%s', $cacheDir, basename($metadata['file']));

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

    private function kernelFingerprint(): string
    {
        if ($this->tracksSourceChanges()) {
            return $this->kernelSourceFingerprint();
        }

        $kernelFile = __FILE__;

        return sprintf(
            '%s:%s',
            $kernelFile,
            is_file($kernelFile) ? (string) filemtime($kernelFile) : 'missing',
        );
    }

    private function tracksSourceChanges(): bool
    {
        return $this->debug;
    }

    private function fileFingerprint(string $file): string
    {
        if ($this->tracksSourceChanges()) {
            return sha1_file($file);
        }

        return (string) filemtime($file);
    }

    private function deploymentFingerprint(): string
    {
        $buildId = defined('SYMPRESS_KERNEL_BUILD_ID')
            ? (string) constant('SYMPRESS_KERNEL_BUILD_ID')
            : getenv('SYMPRESS_KERNEL_BUILD_ID');

        if (is_string($buildId) && $buildId !== '') {
            return 'build:' . $buildId;
        }

        $composerLock = sprintf('%s/composer.lock', $this->projectDir);

        if (is_file($composerLock)) {
            return 'composer-lock:' . $this->fileFingerprint($composerLock);
        }

        return 'build:implicit';
    }

    private function kernelSourceFingerprint(): string
    {
        $sourceDir = dirname(__DIR__, 2) . '/src';
        $files = [];

        if (!is_dir($sourceDir)) {
            return 'missing';
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $pathname = $file->getPathname();
            $files[] = sprintf('%s:%s', $pathname, sha1_file($pathname));
        }

        sort($files);

        return hash('sha256', implode('|', $files));
    }

    private function prepareBuilder(ContainerBuilder $builder): void
    {
        $builderId = spl_object_id($builder);

        if (($this->preparedBuilders[$builderId] ?? false) === true) {
            return;
        }

        $this->preparedBuilders[$builderId] = true;
        $this->registerHookLoader($builder);
        $this->registerConsoleApplication($builder);
        $this->registerConsoleAttributes($builder);
        $builder->registerAttributeForAutoconfiguration(
            AsHook::class,
            static function (ChildDefinition $definition, AsHook $attribute, \Reflector $reflector): void {
                if (!$reflector instanceof \ReflectionClass && !$reflector instanceof \ReflectionMethod) {
                    return;
                }

                $tag = $attribute->toTag();

                if ($reflector instanceof \ReflectionMethod && $attribute->method === '__invoke') {
                    $tag['method'] = $reflector->getName();
                }

                $definition->addTag(HookLoader::TAG, $tag);
            },
        );
        $builder->addCompilerPass(new HookCompilerPass());
        $this->build($builder);
    }

    private function registerConsoleApplication(ContainerBuilder $builder): void
    {
        if (!$builder->hasDefinition(ConsoleApplicationFactory::class)) {
            $builder->setDefinition(
                ConsoleApplicationFactory::class,
                (new Definition(ConsoleApplicationFactory::class))
                    ->setPublic(true)
                    ->setArguments([
                        new Reference(Container::KERNEL_ID),
                        new Reference('console.command_loader'),
                    ]),
            );
        }

        if (!$builder->hasDefinition(Application::class)) {
            $builder->setDefinition(
                Application::class,
                (new Definition(Application::class))
                    ->setPublic(true)
                    ->setFactory([
                        new Reference(ConsoleApplicationFactory::class),
                        'create',
                    ]),
            );
        }

        if (!$builder->hasDefinition(WpCliConsoleBridge::class)) {
            $builder->setDefinition(
                WpCliConsoleBridge::class,
                (new Definition(WpCliConsoleBridge::class))
                    ->setArguments([new Reference(Application::class)])
                    ->addTag(
                        HookLoader::TAG,
                        [
                            'hook' => 'muplugins_loaded',
                            'method' => 'register',
                            'priority' => 1,
                        ],
                    ),
            );
        }
    }

    private function registerConsoleAttributes(ContainerBuilder $builder): void
    {
        $builder->registerAttributeForAutoconfiguration(
            AsCommand::class,
            static function (ChildDefinition $definition): void {
                $definition->addTag('console.command');
            },
        );
    }

    private function registerHookLoader(ContainerBuilder $builder): void
    {
        if ($builder->hasDefinition(HookLoader::class)) {
            return;
        }

        $builder->setDefinition(
            HookLoader::class,
            (new Definition(HookLoader::class))
                ->setPublic(true)
                ->setArguments([null, []]),
        );
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
        if (!class_exists($class, false)) {
            throw new \RuntimeException(
                sprintf('Compiled container class "%s" was not loaded.', $class),
            );
        }

        $container = new $class();

        if (!$container instanceof PsrContainerInterface) {
            throw new \RuntimeException(
                sprintf(
                    'Compiled container "%s" must implement %s.',
                    $class,
                    PsrContainerInterface::class,
                ),
            );
        }

        return $container;
    }
}
