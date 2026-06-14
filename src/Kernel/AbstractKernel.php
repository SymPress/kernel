<?php

declare(strict_types=1);

namespace SymPress\Kernel\Kernel;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use SymPress\Kernel\App;
use SymPress\Kernel\Attribute\AsHook;
use SymPress\Kernel\Attribute\Route;
use SymPress\Kernel\Bundle\BundleInterface;
use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Console\ConsoleApplicationFactory;
use SymPress\Kernel\Console\WpCliConsoleBridge;
use SymPress\Kernel\Container;
use SymPress\Kernel\DependencyInjection\EnvironmentParameterLoader;
use SymPress\Kernel\Discovery\BundleDiscovery;
use SymPress\Kernel\EnvConfig;
use SymPress\Kernel\Hook\HookCompilerPass;
use SymPress\Kernel\Hook\HookLoader;
use SymPress\Kernel\Resolver\ActivePackageResolver;
use SymPress\Kernel\Routing\RouteCompilerPass;
use SymPress\Kernel\Routing\RouteLoader;
use SymPress\Kernel\SiteConfig;
use SymPress\Kernel\Translation\TranslationLoader;
use SymPress\Kernel\WpContext;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Config\Resource\SelfCheckingResourceChecker;
use Symfony\Component\Config\ResourceCheckerConfigCacheFactory;
use Symfony\Component\Config\ResourceCheckerInterface;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\DependencyInjection\Config\ContainerParametersResourceChecker;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\DependencyInjection\AddConsoleCommandPass;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\AddBehaviorDescribingTagsPass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface as SymfonyDiContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Compiler\MergeExtensionConfigurationPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\Compiler\ResettableServicePass;
use Symfony\Component\DependencyInjection\EnvVarLoaderInterface;
use Symfony\Component\DependencyInjection\EnvVarProcessor;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Kernel\KernelInterface as DependencyInjectionKernelInterface;
use Symfony\Component\DependencyInjection\Loader\ClosureLoader;
use Symfony\Component\DependencyInjection\Loader\DirectoryLoader;
use Symfony\Component\DependencyInjection\Loader\GlobFileLoader;
use Symfony\Component\DependencyInjection\Loader\IniFileLoader;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBag;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ReverseContainer;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\DependencyInjection\ServicesResetter;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface as SymfonyKernelInterface;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Service\ServiceSubscriberInterface;

abstract class AbstractKernel implements KernelInterface
{
    protected readonly SiteConfig $config;
    protected readonly WpContext $context;
    protected bool $booted = false;
    protected ?float $startTime = null;
    protected ?Container $container = null;
    protected ?BundleRegistry $bundleRegistry = null;

    /** @var array<string, BundleInterface> */
    protected array $bundles = [];

    /** @var array<int, true> */
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

        if ($this->environment === '') {
            throw new \InvalidArgumentException(
                sprintf('Invalid environment provided to "%s": the environment cannot be empty.', static::class),
            );
        }
    }

    protected string $environment;
    protected bool $debug;

    public function __clone()
    {
        $this->booted = false;
        $this->startTime = null;
        $this->container = null;
        $this->bundleRegistry = null;
        $this->bundles = [];
        $this->preparedBuilders = [];
    }

    /** @return array{project_dir: string, environment: string, debug: bool} */
    public function __serialize(): array
    {
        return [
            'project_dir'  => $this->projectDir,
            'environment'  => $this->environment,
            'debug'        => $this->debug,
        ];
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        $projectDir = $data['project_dir'] ?? $data["\0*\0projectDir"] ?? null;
        $environment = $data['environment'] ?? $data["\0*\0environment"] ?? null;
        $debug = $data['debug'] ?? $data["\0*\0debug"] ?? null;

        if (!is_string($projectDir) || !is_string($environment) || !is_bool($debug)) {
            throw new \BadMethodCallException(sprintf('Cannot unserialize %s.', static::class));
        }

        $this->__construct($projectDir, $environment, $debug);
    }

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

    public function getCharset(): string
    {
        return 'UTF-8';
    }

    public function getCacheDir(): string
    {
        $dir = $this->serverString('APP_CACHE_DIR');

        if ($dir !== null) {
            return sprintf('%s/kernel', $this->environmentDirectory($dir));
        }

        return sprintf('%s/var/cache/%s/kernel', $this->projectDir, $this->environment);
    }

    public function getBuildDir(): string
    {
        $dir = $this->serverString('APP_BUILD_DIR');

        if ($dir !== null) {
            return sprintf('%s/kernel', $this->environmentDirectory($dir));
        }

        return $this->getCacheDir();
    }

    public function getShareDir(): ?string
    {
        $dir = $this->serverNullableDirectory('APP_SHARE_DIR');

        if ($dir !== null) {
            return sprintf('%s/kernel', $this->environmentDirectory($dir));
        }

        if ($this->serverValueIsFalse('APP_SHARE_DIR')) {
            return null;
        }

        return $this->getCacheDir();
    }

    public function getLogDir(): ?string
    {
        $dir = $this->serverNullableDirectory('APP_LOG_DIR');

        if ($dir !== null) {
            return $this->environmentDirectory($dir);
        }

        if ($this->serverValueIsFalse('APP_LOG_DIR')) {
            return null;
        }

        return sprintf('%s/var/log', $this->projectDir);
    }

    public function getStartTime(): float
    {
        return $this->debug && $this->startTime !== null ? $this->startTime : -\INF;
    }

    public function getContainer(): Container
    {
        if (!$this->container instanceof Container) {
            throw new \LogicException('Cannot retrieve the container from a non-booted kernel.');
        }

        return $this->container;
    }

    /** @return array<string, BundleInterface> */
    public function getBundles(): array
    {
        if (!$this->bundleRegistry instanceof BundleRegistry) {
            $this->discoverBundles();
        }

        return $this->bundles;
    }

    public function getBundle(string $name): BundleInterface
    {
        $bundles = $this->getBundles();

        if (isset($bundles[$name])) {
            return $bundles[$name];
        }

        throw new \InvalidArgumentException(
            sprintf('Bundle "%s" does not exist or it is not enabled.', $name),
        );
    }

    public function registerBundles(): iterable
    {
        return $this->getBundles();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
    }

    public function handle(
        Request $request,
        int $type = HttpKernelInterface::MAIN_REQUEST,
        bool $catch = true,
    ): Response {
        $container = $this->getContainer();

        if ($container->has('http_kernel')) {
            $httpKernel = $container->get('http_kernel');

            if (!$httpKernel instanceof HttpKernelInterface) {
                throw new \LogicException('The "http_kernel" service is not available.');
            }

            return $httpKernel->handle($request, $type, $catch);
        }

        if ($container->has(RouteLoader::class)) {
            $routeLoader = $container->get(RouteLoader::class);

            if ($routeLoader instanceof RouteLoader) {
                return $routeLoader->handle($request) ?? new Response('', Response::HTTP_NOT_FOUND);
            }
        }

        return new Response('', Response::HTTP_NOT_FOUND);
    }

    public function locateResource(string $name): string
    {
        if (!isset($name[0]) || $name[0] !== '@') {
            throw new \InvalidArgumentException(sprintf('A resource name must start with @ ("%s" given).', $name));
        }

        if (str_contains($name, '..')) {
            throw new \RuntimeException(sprintf('File name "%s" contains invalid characters (..).', $name));
        }

        $resource = substr($name, 1);
        $path = '';

        if (str_contains($resource, '/')) {
            [$bundleName, $path] = explode('/', $resource, 2);

            return $this->locateBundleFile($bundleName, $path, $name);
        }

        return $this->locateBundleFile($resource, $path, $name);
    }

    private function locateBundleFile(string $bundleName, string $path, string $resourceName): string
    {
        $file = rtrim($this->getBundle($bundleName)->getPath(), '/') . ($path === '' ? '' : '/' . $path);

        if (file_exists($file)) {
            return $file;
        }

        throw new \InvalidArgumentException(sprintf('Unable to find file "%s".', $resourceName));
    }

    public function createContainer(): Container
    {
        $container = new Container($this->config, $this->context);
        $container->setKernel($this);
        $this->container = $container;

        return $container;
    }

    public function discoverBundles(): BundleRegistry
    {
        $this->bundleRegistry = (new BundleDiscovery(
            new ActivePackageResolver(),
            $this->packagePrefixes(),
            $this->projectDir,
            $this->environment,
        ))->discover();
        $this->bundles = $this->bundleMap($this->bundleRegistry);

        return $this->bundleRegistry;
    }

    public function configureContainer(
        ContainerBuilder $builder,
        Container $container,
        BundleRegistry $bundles,
    ): array {

        $this->bundleRegistry = $bundles;
        $this->bundles = $this->bundleMap($bundles);
        $this->prepareBuilder($builder);
        $builder->setParameter('kernel.project_dir', $this->projectDir);
        $builder->setParameter('kernel.environment', $this->environment);
        $builder->setParameter('container.runtime_mode', '');
        $builder->setParameter('kernel.runtime_environment', '%env(default:kernel.environment:APP_RUNTIME_ENV)%');
        $builder->setParameter(
            'kernel.runtime_mode',
            '%env(query_string:default:container.runtime_mode:APP_RUNTIME_MODE)%',
        );
        $builder->setParameter('kernel.runtime_mode.web', '%env(bool:default::key:web:default:kernel.runtime_mode:)%');
        $builder->setParameter('kernel.runtime_mode.cli', '%env(not:default:kernel.runtime_mode.web:)%');
        $builder->setParameter(
            'kernel.runtime_mode.worker',
            '%env(int:default::key:worker:default:kernel.runtime_mode:)%',
        );
        $builder->setParameter('kernel.debug', $this->debug);
        $builder->setParameter('kernel.cache_dir', $this->getCacheDir());
        $builder->setParameter('kernel.build_dir', $this->getBuildDir());
        $builder->setParameter('kernel.share_dir', $this->getShareDir());
        $builder->setParameter('kernel.logs_dir', $this->getLogDir());
        $builder->setParameter('kernel.package_prefixes', $this->packagePrefixes());
        $builder->setParameter('kernel.translation_paths', $bundles->translationDirectories());
        $builder->setParameter('kernel.bundles', $this->bundleClasses($bundles));
        $builder->setParameter('kernel.bundles_metadata', $this->bundleMetadata($bundles));
        $builder->setParameter('kernel.container_class', 'KernelContainer');
        $builder->setParameter('.kernel.config_dir', sprintf('%s/config', $this->projectDir));
        $builder->setParameter('.container.known_envs', $this->knownEnvironments());
        $this->loadEnvironmentVariablesAsParameters($builder);

        foreach ($bundles->all() as $bundle) {
            $extension = $bundle->bundle()->getContainerExtension();

            if ($extension !== null && !$builder->hasExtension($extension->getAlias())) {
                $builder->registerExtension($extension);
            }

            if ($bundle->bundle() instanceof CompilerPassInterface) {
                $builder->addCompilerPass($bundle->bundle(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -10000);
            }

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
                && $this->useCachedRuntimeContainer($container, $cacheDir, $metadata, $fingerprint)
            ) {
                return;
            }

            $sourceResources = $this->sourceResourceManifest($bundles);
            $sourceFingerprint = $this->sourceResourceFingerprint($sourceResources);
            $cacheKey = substr(hash('sha256', "{$fingerprint}|{$sourceFingerprint}"), 0, 16);
            $containerFile = sprintf('%s/container_%s.php', $cacheDir, $cacheKey);
            clearstatcache(true, $containerFile);

            $class = sprintf('KernelContainer_%s', $cacheKey);
            $runtime = $this->createRuntimeBuilder($container, $class);
            $runtime->compile(true);
            $configResources = $this->configResourceManifest($runtime, $configFiles);
            $dumper = new PhpDumper($runtime);
            $filesystem->dumpFile(
                $containerFile,
                $dumper->dump(
                    [
                        'class'               => $class,
                        'debug'               => $this->debug,
                        'file'                => $containerFile,
                        'build_time'          => $this->containerBuildTime($runtime),
                        'inline_class_loader' => $this->debug,
                    ],
                ),
            );
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

    public function boot(?Container $container = null, ?BundleRegistry $bundles = null): void
    {
        if ($this->booted) {
            return;
        }

        $this->startTime ??= microtime(true);

        if ($this->debug && !isset($_ENV['SHELL_VERBOSITY']) && !isset($_SERVER['SHELL_VERBOSITY'])) {
            if (function_exists('putenv')) {
                putenv('SHELL_VERBOSITY=3');
            }

            $_ENV['SHELL_VERBOSITY'] = '3';
            $_SERVER['SHELL_VERBOSITY'] = '3';
        }

        $container ??= $this->container;
        $bundles ??= $this->bundleRegistry;

        if ($container instanceof Container) {
            $this->container = $container;
        }

        if ($bundles instanceof BundleRegistry) {
            $this->bundleRegistry = $bundles;
            $this->bundles = $this->bundleMap($bundles);

            foreach ($bundles->all() as $metadata) {
                $bundle = $metadata->bundle();
                $bundle->setContainer($container);
                $bundle->boot();
            }
        }

        $this->booted = true;
    }

    public function shutdown(): void
    {
        if (!$this->booted) {
            return;
        }

        foreach (array_reverse($this->bundles) as $bundle) {
            $bundle->shutdown();
            $bundle->setContainer(null);
        }

        $this->booted = false;
        $this->container = null;
        $this->bundleRegistry = null;
        $this->bundles = [];
    }

    /** @return array<string, BundleInterface> */
    private function bundleMap(BundleRegistry $bundles): array
    {
        $map = [];

        foreach ($bundles->all() as $metadata) {
            $bundle = $metadata->bundle();
            $name = $bundle->getName();

            if (isset($map[$name])) {
                throw new \LogicException(sprintf('Trying to register two bundles with the same name "%s".', $name));
            }

            $map[$name] = $bundle;
        }

        return $map;
    }

    /** @return array<string, class-string> */
    private function bundleClasses(BundleRegistry $bundles): array
    {
        $classes = [];

        foreach ($bundles->all() as $metadata) {
            $bundle = $metadata->bundle();
            $classes[$bundle->getName()] = $bundle::class;
        }

        return $classes;
    }

    /** @return array<string, array{path: string, package: string, type: string, entry: string}> */
    private function bundleMetadata(BundleRegistry $bundles): array
    {
        $metadataByName = [];

        foreach ($bundles->all() as $metadata) {
            $bundle = $metadata->bundle();
            $metadataByName[$bundle->getName()] = [
                'path'    => $bundle->getPath(),
                'package' => $metadata->package(),
                'type'    => $metadata->type(),
                'entry'   => $metadata->entry(),
            ];
        }

        return $metadataByName;
    }

    /** @return list<string> */
    private function knownEnvironments(): array
    {
        $known = [
            $this->environment,
            'all',
            'dev',
            'development',
            'local',
            'prod',
            'production',
            'stage',
            'staging',
            'test',
        ];
        $bundlesDefinition = sprintf('%s/config/bundles.php', $this->projectDir);

        if (!is_file($bundlesDefinition)) {
            return $this->normalizeKnownEnvironments($known);
        }

        $configuration = require $bundlesDefinition;

        if (!is_array($configuration)) {
            return $this->normalizeKnownEnvironments($known);
        }

        foreach ($configuration as $envs) {
            if (!is_array($envs)) {
                continue;
            }

            foreach (array_keys($envs) as $environment) {
                if (!is_string($environment)) {
                    continue;
                }

                $known[] = $environment;
            }
        }

        return $this->normalizeKnownEnvironments($known);
    }

    /**
     * @param list<string> $environments
     * @return list<string>
     */
    private function normalizeKnownEnvironments(array $environments): array
    {
        return array_values(
            array_unique(
                array_filter($environments, static fn (string $env): bool => $env !== ''),
            ),
        );
    }

    /** @return array<int, string> */
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

    private function serverString(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? null;

        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function serverNullableDirectory(string $name): ?string
    {
        if ($this->serverValueIsFalse($name)) {
            return null;
        }

        return $this->serverString($name);
    }

    private function serverValueIsFalse(string $name): bool
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? null;

        if ($value === null) {
            return false;
        }

        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) === false;
    }

    private function environmentDirectory(string $directory): string
    {
        if ($directory !== '' && in_array($directory[0], ['/', '\\'], true)) {
            return sprintf('%s/%s', rtrim($directory, '/'), $this->environment);
        }

        if (
            DIRECTORY_SEPARATOR === '\\'
            && isset($directory[1])
            && $directory[1] === ':'
            && preg_match('/^[A-Za-z]:/', $directory) === 1
        ) {
            return sprintf('%s/%s', rtrim($directory, '/'), $this->environment);
        }

        return sprintf('%s/%s/%s', $this->projectDir, trim($directory, '/'), $this->environment);
    }

    /** @return list<string> */
    private function packagePrefixes(): array
    {
        $configured = $this->config->get('KERNEL_PACKAGE_PREFIXES', null);

        if ($configured === null) {
            $configured = $this->composerKernelPackagePrefixes();
        }

        return $this->normalizePackagePrefixes($configured);
    }

    private function composerKernelPackagePrefixes(): mixed
    {
        $composerFile = sprintf('%s/composer.json', $this->projectDir);

        if (!is_file($composerFile)) {
            return null;
        }

        $contents = file_get_contents($composerFile);

        if (!is_string($contents) || $contents === '') {
            return null;
        }

        $metadata = json_decode($contents, true);

        if (!is_array($metadata)) {
            return null;
        }

        $kernel = $metadata['extra']['kernel'] ?? null;

        if (!is_array($kernel)) {
            return null;
        }

        return $kernel['package_prefixes'] ?? $kernel['packagePrefixes'] ?? null;
    }

    /** @return list<string> */
    private function normalizePackagePrefixes(mixed $packagePrefixes): array
    {
        if (is_string($packagePrefixes)) {
            $packagePrefixes = preg_split('/[,\s]+/', $packagePrefixes) ?: [];
        }

        if (!is_array($packagePrefixes)) {
            return [];
        }

        $normalized = [];

        foreach ($packagePrefixes as $prefix) {
            if (!is_scalar($prefix) && !$prefix instanceof \Stringable) {
                continue;
            }

            $prefix = trim((string) $prefix);

            if ($prefix === '') {
                continue;
            }

            $normalized[] = str_ends_with($prefix, '/') ? $prefix : "{$prefix}/";
        }

        return array_values(array_unique($normalized));
    }

    /** @return array<int, string> */
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

    /** @return array<int, string> */
    private function runtimeConfigFiles(BundleRegistry $bundles): array
    {
        $files = [];

        foreach ($this->configDirectories($bundles) as $configDir) {
            $files = [...$files, ...$this->resolveConfigFiles($configDir)];
        }

        return array_values(array_unique($files));
    }

    /** @return array<int, string> */
    private function patterns(string $configDir): array
    {
        $env = $this->environment;
        $extensions = '{php,yaml,yml,ini}';

        return [
            sprintf('%s/packages/*.%s', $configDir, $extensions),
            sprintf('%s/packages/%s/*.%s', $configDir, $env, $extensions),
            sprintf('%s/services.%s', $configDir, $extensions),
            sprintf('%s/services_%s.%s', $configDir, $env, $extensions),
            sprintf('%s/wordpress.%s', $configDir, $extensions),
            sprintf('%s/wordpress_%s.%s', $configDir, $env, $extensions),
        ];
    }

    private function loadConfigFile(ContainerBuilder $builder, string $file): void
    {
        $basename = basename($file);
        $extension = pathinfo($basename, PATHINFO_EXTENSION);

        if (!in_array($extension, ['ini', 'php', 'yaml', 'yml'], true)) {
            throw new \RuntimeException(sprintf('Unsupported config file "%s".', $file));
        }

        $builder->addResource(new FileResource($file));
        $this->containerLoader($builder, dirname($file))->load($basename);
    }

    private function containerLoader(ContainerBuilder $builder, string $currentDir): DelegatingLoader
    {
        $locator = new FileLocator($this, $currentDir);
        $resolver = new LoaderResolver(
            [
                new YamlFileLoader($builder, $locator, $this->environment),
                new IniFileLoader($builder, $locator, $this->environment),
                new PhpFileLoader($builder, $locator, $this->environment),
                new GlobFileLoader($builder, $locator, $this->environment),
                new DirectoryLoader($builder, $locator, $this->environment),
                new ClosureLoader($builder, $this->environment),
            ],
        );

        return new DelegatingLoader($resolver);
    }

    private function loadEnvironmentVariablesAsParameters(ContainerBuilder $builder): void
    {
        (new EnvironmentParameterLoader())->load(
            $builder,
            array_merge($_SERVER, $_ENV),
            $this->config->get('KERNEL_ENV_PARAMETERS', null),
        );
    }

    /** @param array<int, string> $configFiles */
    private function fingerprint(BundleRegistry $bundles, array $configFiles): string
    {
        sort($configFiles);

        $parts = [
            $this->projectDir,
            $this->environment,
            (string) (int) $this->debug,
            $this->deploymentFingerprint(),
            $this->kernelFingerprint(),
            ...$bundles->identityFingerprintParts(),
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

        if (!$this->configResourcesAreFresh($metadata['config_resources'] ?? null)) {
            return false;
        }

        if (
            $this->shouldValidateCachedSourceResources()
            && !$this->sourceResourcesAreFresh($metadata['source_resources'] ?? null)
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
        $packageDir = dirname(__DIR__, 2);
        $composerFile = sprintf('%s/composer.json', $packageDir);

        return hash(
            'sha256',
            implode(
                '|',
                [
                    $packageDir,
                    sprintf('%s:%s', $composerFile, $this->fileFingerprint($composerFile)),
                ],
            ),
        );
    }

    private function tracksSourceChanges(): bool
    {
        return $this->debug && !$this->resourceTrackingDisabled();
    }

    private function resourceTrackingDisabled(): bool
    {
        $value = $_SERVER['SYMFONY_DISABLE_RESOURCE_TRACKING']
            ?? $_ENV['SYMFONY_DISABLE_RESOURCE_TRACKING']
            ?? null;

        if ($value === null) {
            return false;
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? ((string) $value !== '');
    }

    private function fileFingerprint(string $file): string
    {
        if (!is_file($file)) {
            return 'missing';
        }

        if ($this->tracksSourceChanges()) {
            return sha1_file($file);
        }

        return $this->sourceFileMtime($file);
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

    /** @return array<string, string> */
    private function sourceResourceManifest(BundleRegistry $bundles): array
    {
        $resources = [];
        $this->collectSourceResources(dirname(__DIR__, 2) . '/src', $resources);

        foreach ($bundles->all() as $metadata) {
            $this->collectSourceResources(sprintf('%s/src', rtrim($metadata->path(), '/')), $resources, 'php');

            $bundleFile = (new \ReflectionObject($metadata->bundle()))->getFileName();

            if (!is_string($bundleFile)) {
                continue;
            }

            $resources[$bundleFile] = $this->sourceFileMtime($bundleFile);
        }

        ksort($resources);

        return $resources;
    }

    /**
     * @param array<string, string> $resources
     */
    private function collectSourceResources(
        string $directory,
        array &$resources,
        ?string $fileExtension = null,
    ): void {

        if (!is_dir($directory)) {
            return;
        }

        $resources[$directory] = $this->sourceDirectoryMtime($directory);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $resource) {
            if (!$resource instanceof \SplFileInfo) {
                continue;
            }

            $pathname = $resource->getPathname();

            if ($resource->isDir()) {
                $resources[$pathname] = $this->sourceDirectoryMtime($pathname);

                continue;
            }

            if (!$resource->isFile()) {
                continue;
            }

            if ($fileExtension !== null && $resource->getExtension() !== $fileExtension) {
                continue;
            }

            $resources[$pathname] = $this->sourceFileMtime($pathname);
        }
    }

    private function sourceResourcesAreFresh(mixed $resources): bool
    {
        if (!is_array($resources) || $resources === []) {
            return false;
        }

        foreach ($resources as $path => $expected) {
            if (!is_string($path) || !is_string($expected)) {
                return false;
            }

            $actual = str_starts_with($expected, 'dir:')
                ? $this->sourceDirectoryMtime($path)
                : $this->sourceFileMtime($path);

            if ($actual !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, string> $configFiles
     * @return array<string, string>
     */
    private function configResourceManifest(ContainerBuilder $builder, array $configFiles): array
    {
        $resources = [];

        foreach ($configFiles as $file) {
            if ($file === '') {
                continue;
            }

            $resources[$file] = $this->fileFingerprint($file);
        }

        foreach ($builder->getResources() as $resource) {
            if ($resource instanceof FileResource) {
                $file = $resource->getResource();
                $resources[$file] = $this->fileFingerprint($file);

                continue;
            }

            if (!$resource instanceof FileExistenceResource) {
                continue;
            }

            $file = $resource->getResource();
            $resources[sprintf('exists:%s', $file)] = file_exists($file) ? 'exists:1' : 'exists:0';
        }

        ksort($resources);

        return $resources;
    }

    private function configResourcesAreFresh(mixed $resources): bool
    {
        if (!is_array($resources) || $resources === []) {
            return false;
        }

        foreach ($resources as $path => $expected) {
            if (!is_string($path) || !is_string($expected)) {
                return false;
            }

            if (str_starts_with($path, 'exists:')) {
                $actual = file_exists(substr($path, 7)) ? 'exists:1' : 'exists:0';

                if ($actual !== $expected) {
                    return false;
                }

                continue;
            }

            if ($this->fileFingerprint($path) !== $expected) {
                return false;
            }
        }

        return true;
    }

    private function shouldValidateCachedSourceResources(): bool
    {
        $value = $_SERVER['SYMPRESS_KERNEL_VALIDATE_SOURCE_RESOURCES']
            ?? $_ENV['SYMPRESS_KERNEL_VALIDATE_SOURCE_RESOURCES']
            ?? null;

        if ($value === null) {
            return false;
        }

        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) === true;
    }

    /** @param array<string, string> $resources */
    private function sourceResourceFingerprint(array $resources): string
    {
        ksort($resources);
        $parts = [];

        foreach ($resources as $path => $fingerprint) {
            $parts[] = sprintf('%s:%s', $path, $fingerprint);
        }

        return hash('sha256', implode('|', $parts));
    }

    private function sourceDirectoryMtime(string $directory): string
    {
        if (!is_dir($directory)) {
            return 'missing';
        }

        return sprintf('dir:%s', (string) filemtime($directory));
    }

    private function sourceFileMtime(string $file): string
    {
        if (!is_file($file)) {
            return 'missing';
        }

        return sprintf('file:%s:%s', (string) filemtime($file), (string) filesize($file));
    }

    private function prepareBuilder(ContainerBuilder $builder): void
    {
        $builderId = spl_object_id($builder);

        if (($this->preparedBuilders[$builderId] ?? false) === true) {
            return;
        }

        $this->preparedBuilders[$builderId] = true;
        $this->registerCoreContainerServices($builder);
        $this->registerTranslationLoader($builder);
        $this->registerHookLoader($builder);
        $this->registerRouteLoader($builder);
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
        $this->registerRouteAttributes($builder);
        $builder->addCompilerPass(new HookCompilerPass());
        $builder->addCompilerPass(new RouteCompilerPass());
        $this->build($builder);
    }

    private function registerCoreContainerServices(ContainerBuilder $builder): void
    {
        if (!$builder->has('kernel')) {
            $builder->setAlias('kernel', Container::KERNEL_ID)->setPublic(true);
        }

        $builder->setAlias(SymfonyKernelInterface::class, Container::KERNEL_ID)
            ->setPublic(true);
        $builder->setAlias(DependencyInjectionKernelInterface::class, Container::KERNEL_ID)
            ->setPublic(true);

        $this->registerFilesystemService($builder);
        $this->registerEventDispatcherServices($builder);
        $this->registerClockService($builder);
        $this->registerExpressionLanguageService($builder);

        if (!$builder->hasDefinition('parameter_bag')) {
            $builder->setDefinition(
                'parameter_bag',
                (new Definition(ContainerBag::class))
                    ->setArguments([new Reference('service_container')]),
            );
        }

        $builder->setAlias(ContainerBagInterface::class, 'parameter_bag')->setPublic(false);
        $builder->setAlias(ParameterBagInterface::class, 'parameter_bag')->setPublic(false);

        if (!$builder->hasDefinition('file_locator')) {
            $builder->setDefinition(
                'file_locator',
                (new Definition(FileLocator::class))
                    ->setArguments([new Reference(Container::KERNEL_ID)]),
            );
        }

        $builder->setAlias(FileLocator::class, 'file_locator')->setPublic(false);

        if (!$builder->hasDefinition('reverse_container')) {
            $builder->setDefinition(
                'reverse_container',
                (new Definition(ReverseContainer::class))
                    ->setArguments([
                        new Reference('service_container'),
                        new ServiceLocatorArgument([]),
                    ]),
            );
        }

        $builder->setAlias(ReverseContainer::class, 'reverse_container')->setPublic(false);

        if (!$builder->hasDefinition('config_cache_factory')) {
            $builder->setDefinition(
                'config_cache_factory',
                (new Definition(ResourceCheckerConfigCacheFactory::class))
                    ->setArguments([new TaggedIteratorArgument('config_cache.resource_checker')]),
            );
        }

        if (!$builder->hasDefinition('dependency_injection.config.container_parameters_resource_checker')) {
            $builder->setDefinition(
                'dependency_injection.config.container_parameters_resource_checker',
                (new Definition(ContainerParametersResourceChecker::class))
                    ->setArguments([new Reference('service_container')])
                    ->addTag('config_cache.resource_checker', ['priority' => -980]),
            );
        }

        if (!$builder->hasDefinition('config.resource.self_checking_resource_checker')) {
            $builder->setDefinition(
                'config.resource.self_checking_resource_checker',
                (new Definition(SelfCheckingResourceChecker::class))
                    ->addTag('config_cache.resource_checker', ['priority' => -990]),
            );
        }

        if (!$builder->hasDefinition('services_resetter')) {
            $builder->setDefinition(
                'services_resetter',
                (new Definition(ServicesResetter::class))
                    ->setPublic(true)
                    ->setArguments([new IteratorArgument([]), []]),
            );
        }

        $builder->setAlias(ServicesResetterInterface::class, 'services_resetter')->setPublic(true);

        if (!$builder->hasDefinition('container.env_var_processor')) {
            $builder->setDefinition(
                'container.env_var_processor',
                (new Definition(EnvVarProcessor::class))
                    ->setArguments([
                        new Reference('service_container'),
                        new TaggedIteratorArgument('container.env_var_loader'),
                    ])
                    ->addTag('container.env_var_processor')
                    ->addTag('kernel.reset', ['method' => 'reset']),
            );
        }

        $builder->registerForAutoconfiguration(EnvVarLoaderInterface::class)
            ->addTag('container.env_var_loader');
        $builder->registerForAutoconfiguration(EnvVarProcessorInterface::class)
            ->addTag('container.env_var_processor');
        $builder->registerForAutoconfiguration(ResourceCheckerInterface::class)
            ->addTag('config_cache.resource_checker');
        $builder->registerForAutoconfiguration(ServiceLocator::class)
            ->addTag('container.service_locator');
        $builder->registerForAutoconfiguration(ResetInterface::class)
            ->addTag('kernel.reset', ['method' => 'reset']);
        $builder->registerForAutoconfiguration(ServiceSubscriberInterface::class)
            ->addTag('container.service_subscriber');
        $builder->registerForAutoconfiguration(CompilerPassInterface::class)
            ->addTag('container.excluded', ['source' => 'because it is a compiler pass']);
        $builder->registerForAutoconfiguration(\UnitEnum::class)
            ->addTag('container.excluded', ['source' => 'because it is an enum']);
        $builder->registerAttributeForAutoconfiguration(
            \Attribute::class,
            static function (ChildDefinition $definition): void {
                $definition->addTag('container.excluded', ['source' => 'because it is a PHP attribute']);
            },
        );
        $this->registerOptionalAutoconfiguration($builder);

        $builder->addCompilerPass(
            new AddBehaviorDescribingTagsPass(
                [
                    'container.do_not_inline',
                    'container.excluded',
                    'container.hot_path',
                    'container.service_locator',
                    'container.service_subscriber',
                    'event_dispatcher.dispatcher',
                    'kernel.event_listener',
                    'kernel.event_subscriber',
                    'kernel.reset',
                ],
            ),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            200,
        );
        $builder->addCompilerPass(new ResettableServicePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -32);
    }

    private function registerFilesystemService(ContainerBuilder $builder): void
    {
        if (!$builder->hasDefinition('filesystem')) {
            $builder->setDefinition('filesystem', new Definition(Filesystem::class));
        }

        $this->setAliasIfMissing($builder, Filesystem::class, 'filesystem');
    }

    private function registerEventDispatcherServices(ContainerBuilder $builder): void
    {
        $eventDispatcherClass = 'Symfony\Component\EventDispatcher\EventDispatcher';

        if (class_exists($eventDispatcherClass) && !$builder->hasDefinition('event_dispatcher')) {
            $builder->setDefinition(
                'event_dispatcher',
                (new Definition($eventDispatcherClass))
                    ->setPublic(true)
                    ->addTag('container.hot_path')
                    ->addTag('event_dispatcher.dispatcher', ['name' => 'event_dispatcher']),
            );
        }

        $this->registerEventDispatcherAliases($builder);

        $registerListenersPass = 'Symfony\Component\EventDispatcher\DependencyInjection\RegisterListenersPass';

        if (class_exists($registerListenersPass)) {
            $builder->addCompilerPass(new $registerListenersPass(), PassConfig::TYPE_BEFORE_REMOVING);
        }

        foreach (
            [
                'Symfony\Component\EventDispatcher\EventDispatcherInterface' => 'event_dispatcher.dispatcher',
                'Symfony\Component\EventDispatcher\EventSubscriberInterface' => 'kernel.event_subscriber',
            ] as $interface => $tag
        ) {
            if (!interface_exists($interface)) {
                continue;
            }

            $builder->registerForAutoconfiguration($interface)->addTag($tag);
        }

        $asEventListenerClass = 'Symfony\Component\EventDispatcher\Attribute\AsEventListener';

        if (!class_exists($asEventListenerClass)) {
            return;
        }

        $builder->registerAttributeForAutoconfiguration(
            $asEventListenerClass,
            static function (ChildDefinition $definition, object $attribute, \Reflector $reflector): void {
                if (!$reflector instanceof \ReflectionClass && !$reflector instanceof \ReflectionMethod) {
                    return;
                }

                $tagAttributes = array_filter(
                    get_object_vars($attribute),
                    static fn (mixed $value): bool => $value !== null,
                );

                if ($reflector instanceof \ReflectionMethod) {
                    if (isset($tagAttributes['method'])) {
                        throw new \LogicException(
                            sprintf(
                                'AsEventListener attribute cannot declare a method on "%s::%s()".',
                                $reflector->class,
                                $reflector->name,
                            ),
                        );
                    }

                    $tagAttributes['method'] = $reflector->getName();
                }

                $definition->addTag('kernel.event_listener', $tagAttributes);
            },
        );
    }

    private function registerEventDispatcherAliases(ContainerBuilder $builder): void
    {
        if (!$builder->hasDefinition('event_dispatcher') && !$builder->hasAlias('event_dispatcher')) {
            return;
        }

        foreach (
            [
                'Symfony\Component\EventDispatcher\EventDispatcherInterface',
                'Symfony\Contracts\EventDispatcher\EventDispatcherInterface',
                'Psr\EventDispatcher\EventDispatcherInterface',
            ] as $eventDispatcherInterface
        ) {
            if (!interface_exists($eventDispatcherInterface)) {
                continue;
            }

            $this->setAliasIfMissing($builder, $eventDispatcherInterface, 'event_dispatcher', true);
        }
    }

    private function registerClockService(ContainerBuilder $builder): void
    {
        $clockClass = 'Symfony\Component\Clock\Clock';

        if (!class_exists($clockClass)) {
            return;
        }

        if (!$builder->hasDefinition('clock')) {
            $builder->setDefinition('clock', new Definition($clockClass));
        }

        foreach (['Symfony\Component\Clock\ClockInterface', 'Psr\Clock\ClockInterface'] as $clockInterface) {
            if (!interface_exists($clockInterface)) {
                continue;
            }

            $this->setAliasIfMissing($builder, $clockInterface, 'clock');
        }
    }

    private function registerExpressionLanguageService(ContainerBuilder $builder): void
    {
        $expressionLanguageClass = 'Symfony\Component\DependencyInjection\ExpressionLanguage';

        if (!class_exists($expressionLanguageClass) || $builder->hasDefinition('container.expression_language')) {
            return;
        }

        $builder->setDefinition('container.expression_language', new Definition($expressionLanguageClass));
    }

    private function registerOptionalAutoconfiguration(ContainerBuilder $builder): void
    {
        $this->registerLoggerAwareAutoconfiguration($builder);
        $this->registerTestCaseExclusion($builder);
        $this->registerLoaderInterfaceExclusion($builder);
    }

    private function registerLoggerAwareAutoconfiguration(ContainerBuilder $builder): void
    {
        $loggerAwareInterface = 'Psr\Log\LoggerAwareInterface';

        if (!interface_exists($loggerAwareInterface)) {
            return;
        }

        $builder->registerForAutoconfiguration($loggerAwareInterface)
            ->addMethodCall(
                'setLogger',
                [new Reference('logger', SymfonyDiContainerInterface::IGNORE_ON_INVALID_REFERENCE)],
            );
    }

    private function registerTestCaseExclusion(ContainerBuilder $builder): void
    {
        $testCaseClass = 'PHPUnit\Framework\TestCase';

        if (!class_exists($testCaseClass)) {
            return;
        }

        $builder->registerForAutoconfiguration($testCaseClass)
            ->addTag('container.excluded', ['source' => 'because it is a test case']);
    }

    private function registerLoaderInterfaceExclusion(ContainerBuilder $builder): void
    {
        if ($builder->hasDefinition(LoaderInterface::class)) {
            return;
        }

        $builder->setDefinition(
            LoaderInterface::class,
            (new Definition())
                ->setAbstract(true)
                ->addTag('container.excluded', ['source' => 'because it is a loader interface']),
        );
    }

    private function setAliasIfMissing(
        ContainerBuilder $builder,
        string $alias,
        string $target,
        bool $public = false,
    ): void {
        if ($builder->hasAlias($alias) || $builder->hasDefinition($alias)) {
            return;
        }

        $builder->setAlias($alias, $target)->setPublic($public);
    }

    private function registerTranslationLoader(ContainerBuilder $builder): void
    {
        if ($builder->hasDefinition(TranslationLoader::class)) {
            return;
        }

        $builder->setDefinition(
            TranslationLoader::class,
            (new Definition(TranslationLoader::class))
                ->setPublic(true)
                ->setArguments(['%kernel.translation_paths%']),
        );
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

        if ($builder->hasDefinition(WpCliConsoleBridge::class)) {
            return;
        }

        $builder->setDefinition(
            WpCliConsoleBridge::class,
            (new Definition(WpCliConsoleBridge::class))
                ->setArguments([new Reference(Application::class)])
                ->addTag(
                    HookLoader::TAG,
                    [
                        'hook'     => 'muplugins_loaded',
                        'method'   => 'register',
                        'priority' => 1,
                    ],
                ),
        );
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

    private function registerRouteLoader(ContainerBuilder $builder): void
    {
        if ($builder->hasDefinition(RouteLoader::class)) {
            return;
        }

        $builder->setDefinition(
            RouteLoader::class,
            (new Definition(RouteLoader::class))
                ->setPublic(true)
                ->setArguments([null, [], []])
                ->addTag(
                    HookLoader::TAG,
                    [
                        'hook'     => 'template_redirect',
                        'method'   => 'dispatchFrontendRequest',
                        'priority' => 0,
                    ],
                )
                ->addTag(
                    HookLoader::TAG,
                    [
                        'hook'   => 'rest_api_init',
                        'method' => 'registerRestRoutes',
                    ],
                ),
        );
    }

    private function registerRouteAttributes(ContainerBuilder $builder): void
    {
        foreach (
            [
                Route::class,
                'Symfony\Component\Routing\Attribute\Route',
            ] as $attributeClass
        ) {
            if (!class_exists($attributeClass)) {
                continue;
            }

            $builder->registerAttributeForAutoconfiguration(
                $attributeClass,
                static function (ChildDefinition $definition): void {
                    $definition->addTag(RouteLoader::TAG);
                },
            );
        }
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
