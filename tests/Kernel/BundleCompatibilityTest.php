<?php

declare(strict_types=1);

namespace {
    if (!function_exists('apply_filters')) {
        function apply_filters(string $hook, mixed $value): mixed
        {
            return $GLOBALS['kernel_test_filter_values'][$hook] ?? $value;
        }
    }

    if (!function_exists('do_action')) {
        function do_action(string $hook, mixed ...$arguments): void
        {
            $GLOBALS['kernel_test_do_actions'][$hook][] = $arguments;
        }
    }
}

namespace SymPress\Kernel\Tests\Kernel {
    use PHPUnit\Framework\TestCase;
    use SymPress\Kernel\App;
    use SymPress\Kernel\Bundle\AbstractBundle;
    use SymPress\Kernel\Bundle\BundleMetadata;
    use SymPress\Kernel\Bundle\BundleRegistry;
    use SymPress\Kernel\Container;
    use SymPress\Kernel\Discovery\BundleDiscovery;
    use SymPress\Kernel\Kernel\AbstractKernel;
    use SymPress\Kernel\Resolver\ActivePackageResolver;
    use SymPress\Kernel\Tests\Support\TestSiteConfig;
    use SymPress\Kernel\Translation\TranslationLoader;
    use SymPress\Kernel\WpContext;
    use Symfony\Component\DependencyInjection\ContainerBuilder;
    use Symfony\Component\Filesystem\Filesystem;
    use Symfony\Component\DependencyInjection\ServicesResetterInterface;

    final class BundleCompatibilityTest extends TestCase
    {
        /** @var list<string> */
        private array $paths = [];

        protected function setUp(): void
        {
            $GLOBALS['kernel_test_filter_values'] = [];
            $GLOBALS['kernel_test_do_actions'] = [];
            $this->resetApp();
        }

        protected function tearDown(): void
        {
            unset(
                $_ENV['APP_API_KEY'],
                $_ENV['APP_KERNEL_TEST_VALUE'],
                $_ENV['APP_RUNTIME_MODE'],
                $_ENV['APP_SECRET'],
                $_ENV['DB_PASSWORD'],
                $_ENV['WP_AUTH_KEY'],
                $_SERVER['SYMPRESS_KERNEL_VALIDATE_SOURCE_RESOURCES'],
                $GLOBALS['kernel_test_do_actions'],
                $GLOBALS['kernel_test_filter_values'],
            );
            $this->resetApp();

            if ($this->paths === []) {
                return;
            }

            (new Filesystem())->remove($this->paths);
            $this->paths = [];
        }

        public function testSymfonyStyleContainerExtensionIsRegisteredAutomatically(): void
        {
            $builder = new ContainerBuilder();
            $bundle = new AutoExtensionBundle();

            $bundle->build($builder);
            $builder->loadFromExtension('auto_extension', ['message' => 'custom']);
            $builder->compile();

            self::assertSame('custom', $builder->getParameter('auto_extension.message'));
        }

        public function testResourcesConfigDirectoryIsLoaded(): void
        {
            $projectDir = $this->tmpPath('project');
            $bundleDir = $this->tmpPath('resource-bundle');
            $this->writePhpConfig("{$bundleDir}/Resources/config/services.php", 'resources');
            file_put_contents("{$bundleDir}/composer.json", '{}');

            $kernel = $this->kernel($projectDir);
            $container = $kernel->createContainer();
            $loaded = $kernel->configureContainer(
                $container->builder(),
                $container,
                $this->registry($bundleDir),
            );

            self::assertSame('resources', $container->builder()->getParameter('demo.value'));
            self::assertContains("{$bundleDir}/Resources/config/services.php", $loaded);
        }

        public function testConfigBundlesAndFilterBundlesAreDiscovered(): void
        {
            $projectDir = $this->tmpPath('manual-project');
            $this->writeBundlesConfig($projectDir);
            $GLOBALS['kernel_test_filter_values']['symfony_register_bundles'] = [
                FilteredDiscoveryBundle::class,
            ];

            $registry = (new BundleDiscovery(
                new ActivePackageResolver(),
                ['not-installed/'],
                $projectDir,
                'development',
            ))->discover();
            $ids = array_map(
                static fn (BundleMetadata $metadata): string => $metadata->bundle()->id(),
                $registry->all(),
            );

            self::assertContains(ManualDiscoveryBundle::class, $ids);
            self::assertContains(FilteredDiscoveryBundle::class, $ids);
            self::assertNotContains(ProductionOnlyDiscoveryBundle::class, $ids);
        }

        public function testRequiredBundlesAreDiscoveredBeforeTheConsumer(): void
        {
            $projectDir = $this->tmpPath('required-project');
            $this->writeRequiredBundlesConfig($projectDir);

            $registry = (new BundleDiscovery(
                new ActivePackageResolver(),
                ['not-installed/'],
                $projectDir,
                'development',
            ))->discover();
            $ids = array_map(
                static fn (BundleMetadata $metadata): string => $metadata->bundle()->id(),
                $registry->all(),
            );

            self::assertSame(
                [
                    RequiredDependencyBundle::class,
                    RequiredConsumerBundle::class,
                    RequiredOptionalConsumerBundle::class,
                ],
                $ids,
            );
        }

        public function testKernelLifecycleActionsAreDispatched(): void
        {
            App::new(new LifecycleKernel($this->tmpPath('lifecycle-project')))->boot();

            self::assertArrayHasKey(App::ACTION_BOOTING, $GLOBALS['kernel_test_do_actions']);
            self::assertArrayHasKey(App::ACTION_BEFORE_CONTAINER_BUILD, $GLOBALS['kernel_test_do_actions']);
            self::assertArrayHasKey(App::ACTION_CONTAINER_CONFIGURED, $GLOBALS['kernel_test_do_actions']);
            self::assertArrayHasKey(App::ACTION_CONTAINER_READY, $GLOBALS['kernel_test_do_actions']);
            self::assertArrayHasKey(App::ACTION_BOOTED, $GLOBALS['kernel_test_do_actions']);
            self::assertArrayHasKey(App::LEGACY_ACTION_BEFORE_CONTAINER_BUILD, $GLOBALS['kernel_test_do_actions']);
            self::assertArrayHasKey(App::LEGACY_ACTION_CONTAINER_READY, $GLOBALS['kernel_test_do_actions']);
            self::assertArrayHasKey(App::LEGACY_ACTION_CONTAINER_LOADED, $GLOBALS['kernel_test_do_actions']);
            self::assertInstanceOf(
                Container::class,
                $GLOBALS['kernel_test_do_actions'][App::LEGACY_ACTION_CONTAINER_LOADED][0][0],
            );
        }

        public function testDebugMethodsBridgeToOptionalProfilerService(): void
        {
            $profiler = new class {
                public bool $enabled = false;
                public bool $disabled = false;

                public function enable(): void
                {
                    $this->enabled = true;
                    $this->disabled = false;
                }

                public function disable(): void
                {
                    $this->disabled = true;
                    $this->enabled = false;
                }
            };

            $app = App::new(new LifecycleKernel($this->tmpPath('debug-project'), $profiler));
            $app->enableDebug()->boot();

            self::assertTrue($profiler->enabled);
            self::assertFalse($profiler->disabled);

            $app->disableDebug();

            self::assertFalse($profiler->enabled);
            self::assertTrue($profiler->disabled);
        }

        public function testKernelBootRethrowsErrorsAfterDispatchingErrorAction(): void
        {
            $kernel = new class (
                $this->tmpPath('failing-project'),
                'test',
                false,
                new TestSiteConfig('test'),
                WpContext::new()->force(WpContext::CORE),
            ) extends AbstractKernel {
                public function discoverBundles(): BundleRegistry
                {
                    throw new \RuntimeException('discovery failed');
                }
            };

            try {
                App::new($kernel)->boot();
                self::fail('Kernel boot should rethrow discovery failures.');
            } catch (\RuntimeException $exception) {
                self::assertSame('discovery failed', $exception->getMessage());
                self::assertArrayHasKey(App::ACTION_ERROR, $GLOBALS['kernel_test_do_actions']);
                self::assertSame($exception, $GLOBALS['kernel_test_do_actions'][App::ACTION_ERROR][0][0]);
            }
        }

        public function testOnlyAllowedNonSensitiveEnvironmentVariablesAreExposedAsParameters(): void
        {
            $_ENV['APP_RUNTIME_MODE'] = 'web%mode';
            $_ENV['APP_SECRET'] = 'secret';
            $_ENV['APP_API_KEY'] = 'secret';
            $_ENV['DB_PASSWORD'] = 'secret';
            $_ENV['WP_AUTH_KEY'] = 'secret';

            $kernel = $this->kernel($this->tmpPath('env-project'));
            $container = $kernel->createContainer();

            $kernel->configureContainer($container->builder(), $container, new BundleRegistry());

            self::assertSame(
                'web%%mode',
                $container->builder()->getParameter('env.app_runtime_mode'),
            );
            self::assertFalse($container->builder()->hasParameter('env.app_secret'));
            self::assertFalse($container->builder()->hasParameter('env.app_api_key'));
            self::assertFalse($container->builder()->hasParameter('env.db_password'));
            self::assertFalse($container->builder()->hasParameter('env.wp_auth_key'));
        }

        public function testProductionBundleFingerprintChangesWhenSourceFileChanges(): void
        {
            $bundleDir = $this->tmpPath('fingerprint-bundle');
            $sourceDir = "{$bundleDir}/src";
            $sourceFile = "{$sourceDir}/DemoService.php";
            mkdir($sourceDir, 0777, true);
            file_put_contents("{$bundleDir}/composer.json", '{}');
            file_put_contents($sourceFile, '<?php final class DemoService {}');

            $metadata = $this->registry($bundleDir)->all()[0];
            $first = $metadata->fingerprintParts(false);

            file_put_contents($sourceFile, '<?php final class DemoService { public function touch(): void {} }');
            touch($sourceFile, time() + 5);
            clearstatcache(true, $sourceFile);

            self::assertNotSame($first, $metadata->fingerprintParts(false));
        }

        public function testProductionRuntimeCacheDoesNotStatBundleSourceFilesByDefault(): void
        {
            $projectDir = $this->tmpPath('runtime-cache-project');
            $bundleDir = $this->tmpPath('runtime-cache-bundle');
            $sourceDir = "{$bundleDir}/src";
            $sourceFile = "{$sourceDir}/DemoService.php";
            mkdir($sourceDir, 0777, true);
            file_put_contents("{$bundleDir}/composer.json", '{}');
            file_put_contents($sourceFile, '<?php final class DemoService {}');

            $registry = $this->registry($bundleDir);
            $kernel = $this->kernel($projectDir);
            $container = $kernel->createContainer();
            $loaded = $kernel->configureContainer($container->builder(), $container, $registry);
            $kernel->createRuntimeContainer($container, $registry, $loaded);

            $cachedKernel = $this->kernel($projectDir);
            $cachedContainer = $cachedKernel->createContainer();
            self::assertTrue($cachedKernel->tryUseRuntimeContainer($cachedContainer, $registry));

            file_put_contents($sourceFile, '<?php final class DemoService { public function touch(): void {} }');
            touch($sourceFile, time() + 5);
            clearstatcache(true, $sourceFile);

            $staleKernel = $this->kernel($projectDir);
            $staleContainer = $staleKernel->createContainer();
            self::assertTrue($staleKernel->tryUseRuntimeContainer($staleContainer, $registry));
        }

        public function testRuntimeCacheCanValidateBundleSourceFilesWhenEnabled(): void
        {
            $_SERVER['SYMPRESS_KERNEL_VALIDATE_SOURCE_RESOURCES'] = '1';
            $projectDir = $this->tmpPath('runtime-source-validation-project');
            $bundleDir = $this->tmpPath('runtime-source-validation-bundle');
            $sourceDir = "{$bundleDir}/src";
            $sourceFile = "{$sourceDir}/DemoService.php";
            mkdir($sourceDir, 0777, true);
            file_put_contents("{$bundleDir}/composer.json", '{}');
            file_put_contents($sourceFile, '<?php final class DemoService {}');

            $registry = $this->registry($bundleDir);
            $kernel = $this->kernel($projectDir);
            $container = $kernel->createContainer();
            $loaded = $kernel->configureContainer($container->builder(), $container, $registry);
            $kernel->createRuntimeContainer($container, $registry, $loaded);

            file_put_contents($sourceFile, '<?php final class DemoService { public function touch(): void {} }');
            touch($sourceFile, time() + 5);
            clearstatcache(true, $sourceFile);

            $staleKernel = $this->kernel($projectDir);
            $staleContainer = $staleKernel->createContainer();
            self::assertFalse($staleKernel->tryUseRuntimeContainer($staleContainer, $registry));
        }

        public function testRuntimeCacheInvalidatesWhenImportedConfigFileChanges(): void
        {
            $projectDir = $this->tmpPath('runtime-import-project');
            $configDir = "{$projectDir}/config";
            $importedFile = "{$configDir}/imported.php";
            mkdir($configDir, 0777, true);
            $this->writeImportingConfig("{$configDir}/services.php", 'first');

            $kernel = $this->kernel($projectDir);
            $container = $kernel->createContainer();
            $loaded = $kernel->configureContainer($container->builder(), $container, new BundleRegistry());
            $kernel->createRuntimeContainer($container, new BundleRegistry(), $loaded);

            $cachedKernel = $this->kernel($projectDir);
            $cachedContainer = $cachedKernel->createContainer();
            self::assertTrue($cachedKernel->tryUseRuntimeContainer($cachedContainer, new BundleRegistry()));

            $this->writeImportedConfig($importedFile, 'second');
            touch($importedFile, time() + 5);
            clearstatcache(true, $importedFile);

            $staleKernel = $this->kernel($projectDir);
            $staleContainer = $staleKernel->createContainer();
            self::assertFalse($staleKernel->tryUseRuntimeContainer($staleContainer, new BundleRegistry()));
        }

        public function testTranslationLoaderReceivesBundleTranslationPaths(): void
        {
            $projectDir = $this->tmpPath('translation-project');
            $bundleDir = $this->tmpPath('translation-bundle');
            $translationDir = "{$bundleDir}/Resources/translations";
            mkdir($translationDir, 0777, true);
            file_put_contents("{$bundleDir}/composer.json", '{}');
            file_put_contents("{$translationDir}/messages.en.xliff", $this->xliff());

            $kernel = $this->kernel($projectDir);
            $container = $kernel->createContainer();
            $loaded = $kernel->configureContainer(
                $container->builder(),
                $container,
                $this->registry($bundleDir),
            );
            $kernel->createRuntimeContainer($container, $this->registry($bundleDir), $loaded);

            $loader = $container->get(TranslationLoader::class);

            self::assertInstanceOf(TranslationLoader::class, $loader);
            self::assertSame(
                ['hello' => 'Hallo'],
                $loader->loadTranslations('en')['sympress/test-bundle'],
            );
        }

        public function testConfigurableBundleExtensionIsImplicitlyLoaded(): void
        {
            $projectDir = $this->tmpPath('configurable-project');
            $bundleDir = $this->tmpPath('configurable-bundle');
            mkdir($bundleDir, 0777, true);
            file_put_contents("{$bundleDir}/composer.json", '{}');
            $bundle = new ConfigurableKernelBundle($bundleDir);
            $registry = $this->registryWithBundle($bundleDir, $bundle);

            $kernel = $this->kernel($projectDir);
            $container = $kernel->createContainer();
            $loaded = $kernel->configureContainer($container->builder(), $container, $registry);
            $kernel->createRuntimeContainer($container, $registry, $loaded);

            self::assertSame('implicit', $container->getParameter('configurable_bundle.message'));
        }

        public function testKernelExposesSymfonyBundleApiAndLocatesResources(): void
        {
            $projectDir = $this->tmpPath('api-project');
            $bundleDir = $this->tmpPath('api-bundle');
            $resourceDir = "{$bundleDir}/Resources/views";
            mkdir($resourceDir, 0777, true);
            file_put_contents("{$bundleDir}/composer.json", '{}');
            file_put_contents("{$resourceDir}/panel.php", '<?php return "panel";');
            $bundle = new TrackingKernelBundle($bundleDir);
            $registry = $this->registryWithBundle($bundleDir, $bundle);

            $kernel = $this->kernel($projectDir);
            $container = $kernel->createContainer();
            $loaded = $kernel->configureContainer($container->builder(), $container, $registry);
            $kernel->createRuntimeContainer($container, $registry, $loaded);
            $kernel->boot($container, $registry);

            self::assertSame($container, $kernel->getContainer());
            self::assertArrayHasKey($bundle->getName(), $kernel->getBundles());
            self::assertSame($bundle, $kernel->getBundle($bundle->getName()));
            self::assertSame("{$resourceDir}/panel.php", $kernel->locateResource('@TrackingKernelBundle/Resources/views/panel.php'));
            self::assertTrue($bundle->booted);

            $kernel->shutdown();

            self::assertTrue($bundle->shutdown);
            self::assertFalse($bundle->hasContainer());
        }

        public function testCoreSymfonyServicesAndResettableAutoconfigurationAreAvailable(): void
        {
            ResettableKernelService::resetState();

            $projectDir = $this->tmpPath('core-services-project');
            $this->writeCoreServicesConfig("{$projectDir}/config/services.php");

            $kernel = $this->kernel($projectDir);
            $container = $kernel->createContainer();
            $loaded = $kernel->configureContainer($container->builder(), $container, new BundleRegistry());

            self::assertTrue($container->builder()->hasDefinition('filesystem'));

            $kernel->createRuntimeContainer($container, new BundleRegistry(), $loaded);

            self::assertSame($projectDir, $container->getParameter('kernel.project_dir'));
            self::assertSame('test', $container->getParameter('kernel.runtime_environment'));
            self::assertInstanceOf(ContainerBagConsumer::class, $container->get(ContainerBagConsumer::class));
            self::assertSame('test', $container->get(ContainerBagConsumer::class)->environment());

            $container->get(ResettableKernelService::class)->touch();
            self::assertTrue(ResettableKernelService::$touched);
            self::assertFalse(ResettableKernelService::$reset);

            $container->get(ServicesResetterInterface::class)->reset();

            self::assertTrue(ResettableKernelService::$reset);
        }

        private function kernel(string $projectDir): AbstractKernel
        {
            return new class (
                $projectDir,
                'test',
                false,
                new TestSiteConfig('test'),
                WpContext::new()->force(WpContext::CORE),
            ) extends AbstractKernel {
            };
        }

        private function registry(string $bundleDir): BundleRegistry
        {
            return $this->registryWithBundle(
                $bundleDir,
                new class ($bundleDir) extends AbstractBundle {
                    public function __construct(
                        private readonly string $bundlePath,
                    ) {
                    }

                    public function path(): string
                    {
                        return $this->bundlePath;
                    }
                },
            );
        }

        private function registryWithBundle(string $bundleDir, AbstractBundle $bundle): BundleRegistry
        {
            return (new BundleRegistry())->add(
                new BundleMetadata(
                    'sympress/test-bundle',
                    'wordpress-plugin',
                    'test-bundle/test-bundle.php',
                    $bundleDir,
                    "{$bundleDir}/composer.json",
                    $bundle,
                ),
            );
        }

        private function tmpPath(string $prefix): string
        {
            $path = sprintf('%s/%s-%s', sys_get_temp_dir(), $prefix, uniqid('', true));
            $this->paths[] = $path;

            return $path;
        }

        private function writePhpConfig(string $file, string $value): void
        {
            $dir = dirname($file);

            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            file_put_contents(
                $file,
                sprintf(
                    <<<'PHP'
<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->parameters()->set('demo.value', '%s');
};
PHP
                    ,
                    $value,
                ),
            );
        }

        private function writeImportingConfig(string $file, string $value): void
        {
            $this->writeImportedConfig(sprintf('%s/imported.php', dirname($file)), $value);
            file_put_contents(
                $file,
                <<<'PHP'
<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->import('imported.php');
};
PHP
                ,
            );
        }

        private function writeImportedConfig(string $file, string $value): void
        {
            file_put_contents(
                $file,
                sprintf(
                    <<<'PHP'
<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->parameters()->set('imported.value', '%s');
};
PHP
                    ,
                    $value,
                ),
            );
        }

        private function writeBundlesConfig(string $projectDir): void
        {
            $configDir = "{$projectDir}/config";

            if (!is_dir($configDir)) {
                mkdir($configDir, 0777, true);
            }

            file_put_contents(
                "{$configDir}/bundles.php",
                sprintf(
                    <<<'PHP'
<?php

declare(strict_types=1);

return [
    %s::class => ['dev' => true],
    %s::class => ['prod' => true],
];
PHP
                    ,
                    ManualDiscoveryBundle::class,
                    ProductionOnlyDiscoveryBundle::class,
                ),
            );
        }

        private function writeRequiredBundlesConfig(string $projectDir): void
        {
            $configDir = "{$projectDir}/config";

            if (!is_dir($configDir)) {
                mkdir($configDir, 0777, true);
            }

            file_put_contents(
                "{$configDir}/bundles.php",
                sprintf(
                    <<<'PHP'
<?php

declare(strict_types=1);

return [
    %s::class => ['all' => true],
    %s::class => ['all' => true],
];
PHP
                    ,
                    RequiredConsumerBundle::class,
                    RequiredOptionalConsumerBundle::class,
                ),
            );
        }

        private function writeCoreServicesConfig(string $file): void
        {
            $dir = dirname($file);

            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            file_put_contents(
                $file,
                sprintf(
                    <<<'PHP'
<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    $services->set(%s::class);
    $services->set(%s::class);
};
PHP
                    ,
                    ContainerBagConsumer::class,
                    ResettableKernelService::class,
                ),
            );
        }

        private function xliff(): string
        {
            return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<xliff version="1.2">
    <file source-language="en" target-language="de" datatype="plaintext" original="messages">
        <body>
            <trans-unit id="hello">
                <source>Hello</source>
                <target>Hallo</target>
            </trans-unit>
        </body>
    </file>
</xliff>
XML;
        }

        private function resetApp(): void
        {
            $property = new \ReflectionProperty(App::class, 'app');
            $property->setValue(null, null);
        }
    }

}
