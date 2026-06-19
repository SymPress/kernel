<?php

declare(strict_types=1);

namespace SymPress\Kernel\Kernel;

use SymPress\Kernel\Bundle\BundleInterface;
use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Container;
use SymPress\Kernel\DependencyInjection\EnvironmentParameterLoader;
use SymPress\Kernel\Discovery\BundleDiscovery;
use SymPress\Kernel\EnvConfig;
use SymPress\Kernel\Resolver\ActivePackageResolver;
use SymPress\Kernel\Routing\RouteLoader;
use SymPress\Kernel\SiteConfig;
use SymPress\Kernel\WpContext;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\Loader\ClosureLoader;
use Symfony\Component\DependencyInjection\Loader\DirectoryLoader;
use Symfony\Component\DependencyInjection\Loader\GlobFileLoader;
use Symfony\Component\DependencyInjection\Loader\IniFileLoader;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Config\FileLocator;
use Symfony\Component\HttpKernel\HttpKernelInterface;

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

    private ?CoreServiceRegistrar $coreServiceRegistrar = null;

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
        $this->coreServiceRegistrar = null;
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
        return $this->configuration()->cacheDir();
    }

    public function getBuildDir(): string
    {
        return $this->configuration()->buildDir();
    }

    public function getShareDir(): ?string
    {
        return $this->configuration()->shareDir();
    }

    public function getLogDir(): ?string
    {
        return $this->configuration()->logDir();
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
            $this->configuration()->packagePrefixes(),
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
        $builder->setParameter('kernel.package_prefixes', $this->configuration()->packagePrefixes());
        $builder->setParameter('kernel.translation_paths', $bundles->translationDirectories());
        $builder->setParameter('kernel.bundles', $this->bundleClasses($bundles));
        $builder->setParameter('kernel.bundles_metadata', $this->bundleMetadata($bundles));
        $builder->setParameter('kernel.container_class', 'KernelContainer');
        $builder->setParameter('.kernel.config_dir', sprintf('%s/config', $this->projectDir));
        $builder->setParameter('.container.known_envs', $this->configuration()->knownEnvironments());
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

        foreach ($this->configuration()->configDirectories($bundles) as $configDir) {
            foreach ($this->configuration()->resolveConfigFiles($configDir) as $file) {
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
        return $this->containerCacheManager()->tryUseRuntimeContainer(
            $container,
            $bundles,
            $this->configuration()->runtimeConfigFiles($bundles),
        );
    }

    public function createRuntimeContainer(
        Container $container,
        BundleRegistry $bundles,
        array $configFiles,
    ): void {
        $this->containerCacheManager()->createRuntimeContainer($container, $bundles, $configFiles);
    }

    private function containerCacheManager(): ContainerCacheManager
    {
        return new ContainerCacheManager(
            $this->getCacheDir(),
            $this->debug,
            new ContainerResourceFingerprinter($this->projectDir, $this->environment, $this->debug),
        );
    }

    private function configuration(): KernelConfigurationResolver
    {
        return new KernelConfigurationResolver(
            $this->projectDir,
            $this->environment,
            $this->config,
        );
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
            array_merge(
                $this->configuration()->stringKeyMap($_SERVER),
                $this->configuration()->stringKeyMap($_ENV),
            ),
            $this->config->get('KERNEL_ENV_PARAMETERS', null),
        );
    }

    private function prepareBuilder(ContainerBuilder $builder): void
    {
        $this->coreServiceRegistrar()->prepare($builder, $this->build(...));
    }

    private function coreServiceRegistrar(): CoreServiceRegistrar
    {
        return $this->coreServiceRegistrar ??= new CoreServiceRegistrar();
    }
}
