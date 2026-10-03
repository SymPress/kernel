<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Kernel\CachePath;
use SymPress\Kernel\Kernel\ContainerResourceFingerprinter;
use SymPress\Kernel\Kernel\ResourceFingerprint;
use SymPress\Kernel\Kernel\SiteKernel;
use SymPress\Kernel\Kernel\KernelConfigurationResolver;
use SymPress\Kernel\Tests\Support\TestSiteConfig;
use SymPress\Kernel\WpContext;
use SymPress\Kernel\Discovery\KernelPackageManifestCache;
use SymPress\Kernel\Bundle\AbstractBundle;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Process\Process;
use SymPress\Kernel\Kernel\ContainerCacheManager;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\KernelInterface as HttpKernelInterface;
use Symfony\Component\DependencyInjection\Kernel\KernelInterface as DependencyInjectionKernelInterface;
use Symfony\Component\HttpKernel\Bundle\BundleInterface as HttpBundleInterface;
use Symfony\Component\DependencyInjection\Kernel\BundleInterface as DependencyInjectionBundleInterface;

final class RuntimeCacheCompatibilityTest extends KernelTestCase
{
    public function testRecursiveDirectoryResourcesTrackContentsAdditionRemovalAndIgnoreSymlinkEscapes(): void
    {
        $_SERVER['SYMPRESS_KERNEL_CONTENT_HASHES'] = '1';
        $root = $this->tmpPath('external-resources');
        mkdir($root, 0700, true);
        $file = $root . '/mapped.php';
        file_put_contents($file, '<?php // first');
        $outside = $this->tmpPath('unrelated.php');
        file_put_contents($outside, '<?php // outside');
        symlink($outside, $root . '/link.php');
        $builder = new ContainerBuilder();
        $builder->addResource(new DirectoryResource($root, '/\\.php$/D'));
        $fingerprinter = new ContainerResourceFingerprinter($root, 'test', true);
        $initial = $fingerprinter->configResourceManifest($builder, []);
        self::assertTrue($fingerprinter->configResourcesAreFresh($initial));
        file_put_contents($outside, '<?php // changed outside');
        self::assertTrue($fingerprinter->configResourcesAreFresh($initial));
        $mtime = filemtime($file);
        file_put_contents($file, '<?php // other');
        self::assertIsInt($mtime);
        touch($file, $mtime);
        self::assertFalse($fingerprinter->configResourcesAreFresh($initial));
        $changed = $fingerprinter->refreshConfigResources($initial);
        mkdir($root . '/nested', 0700);
        file_put_contents($root . '/nested/new.php', '<?php // new');
        self::assertFalse($fingerprinter->configResourcesAreFresh($changed));
        $added = $fingerprinter->refreshConfigResources($changed);
        unlink($root . '/nested/new.php');
        self::assertFalse($fingerprinter->configResourcesAreFresh($added));
        self::assertSame($changed, $fingerprinter->refreshConfigResources($added));
    }

    public function testFrameworkLegacyKernelAliasIsTypeCompatible(): void
    {
        $kernel = $this->kernel($this->tmpPath('legacy-interface'));
        self::assertInstanceOf(HttpKernelInterface::class, $kernel);
        self::assertInstanceOf(DependencyInjectionKernelInterface::class, $kernel);
        $bundle = new class extends AbstractBundle {
        };
        self::assertInstanceOf(HttpBundleInterface::class, $bundle);
        self::assertInstanceOf(DependencyInjectionBundleInterface::class, $bundle);
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

    public function testDebugHitSkipsConfigExecutionAndDetectsSameTimestampEdits(): void
    {
        $_SERVER['SYMPRESS_KERNEL_CONTENT_HASHES'] = '1';
        $project = $this->tmpPath('debug-cache');
        mkdir($project . '/config', 0700, true);
        $file = $project . '/config/services.php';
        file_put_contents($file, '<?php $GLOBALS["kernel_config_loads"]++; return static function (\\Symfony\\Component\\DependencyInjection\\Loader\\Configurator\\ContainerConfigurator $c): void { $c->parameters()->set("value", "first"); };');
        $GLOBALS['kernel_config_loads'] = 0;
        $kernel = new SiteKernel($project, 'test', true, new TestSiteConfig('test'), WpContext::new()->force(WpContext::CORE));
        $container = $kernel->createContainer();
        $files = $kernel->configureContainer($container->builder(), $container, new BundleRegistry());
        $kernel->createRuntimeContainer($container, new BundleRegistry(), $files);
        self::assertSame(1, $GLOBALS['kernel_config_loads']);
        $cached = $kernel->createContainer();
        self::assertTrue($kernel->tryUseRuntimeContainer($cached, new BundleRegistry()));
        self::assertSame(1, $GLOBALS['kernel_config_loads']);
        $stamp = filemtime($file);
        file_put_contents($file, str_replace('first', 'other', (string) file_get_contents($file)));
        touch($file, $stamp);
        self::assertFalse($kernel->tryUseRuntimeContainer($kernel->createContainer(), new BundleRegistry()));
        $rebuilt = $kernel->createContainer();
        $files = $kernel->configureContainer($rebuilt->builder(), $rebuilt, new BundleRegistry());
        $kernel->createRuntimeContainer($rebuilt, new BundleRegistry(), $files);
        self::assertSame('other', $rebuilt->getParameter('value'));
        unset($GLOBALS['kernel_config_loads']);
    }

    public function testImportedSameTimestampEditRebuildsActualParameter(): void
    {
        $_SERVER['SYMPRESS_KERNEL_CONTENT_HASHES'] = '1';
        $project = $this->tmpPath('import-content');
        mkdir($project . '/config', 0700, true);
        $this->writeImportingConfig($project . '/config/services.php', 'first');
        $kernel = $this->kernel($project);
        $container = $kernel->createContainer();
        $files = $kernel->configureContainer($container->builder(), $container, new BundleRegistry());
        $kernel->createRuntimeContainer($container, new BundleRegistry(), $files);
        $file = $project . '/config/imported.php';
        $stamp = filemtime($file);
        $this->writeImportedConfig($file, 'other');
        touch($file, $stamp);
        $rebuilt = $kernel->createContainer();
        self::assertFalse($kernel->tryUseRuntimeContainer($rebuilt, new BundleRegistry()));
        $files = $kernel->configureContainer($rebuilt->builder(), $rebuilt, new BundleRegistry());
        $kernel->createRuntimeContainer($rebuilt, new BundleRegistry(), $files);
        self::assertSame('other', $rebuilt->getParameter('imported.value'));
    }

    public function testDiscoveryDetectsSameSecondNewAndRemovedFiles(): void
    {
        $project = $this->tmpPath('discovery-content');
        mkdir($project . '/config/packages', 0700, true);
        $fingerprints = new ContainerResourceFingerprinter($project, 'test', false);
        $resources = $fingerprints->discoveryResourceManifest([$project . '/config']);
        $stamp = filemtime($project . '/config/packages');
        file_put_contents($project . '/config/packages/new.yaml', 'parameters: {}');
        touch($project . '/config/packages', $stamp);
        self::assertFalse($fingerprints->discoveryResourcesAreFresh($resources));
        $resources = $fingerprints->discoveryResourceManifest([$project . '/config']);
        unlink($project . '/config/packages/new.yaml');
        touch($project . '/config/packages', $stamp);
        self::assertFalse($fingerprints->discoveryResourcesAreFresh($resources));
    }

    public function testCacheArtifactsArePrivateAndWarmCacheWorksReadOnly(): void
    {
        $project = $this->tmpPath('private-cache');
        $kernel = $this->kernel($project);
        $container = $kernel->createContainer();
        $files = $kernel->configureContainer($container->builder(), $container, new BundleRegistry());
        $kernel->createRuntimeContainer($container, new BundleRegistry(), $files);
        $directory = $project . '/var/cache/test/kernel';
        self::assertSame(0700, fileperms($directory) & 0777);
        foreach (glob($directory . '/*') ?: [] as $file) {
            self::assertSame(0600, fileperms($file) & 0777);
            chmod($file, 0400);
        }
        chmod($directory, 0500);
        try {
            self::assertTrue($kernel->tryUseRuntimeContainer($kernel->createContainer(), new BundleRegistry()));
        } finally {
            chmod($directory, 0700);
        }
    }

    public function testPublicDefaultRequiresAnExplicitPrivateCache(): void
    {
        $project = $this->tmpPath('public-cache');
        $_SERVER['DOCUMENT_ROOT'] = $project;
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('APP_CACHE_DIR');
            CachePath::resolve($project, 'test');
        } finally {
            unset($_SERVER['DOCUMENT_ROOT']);
        }
    }

    public function testParallelBootPublishesOneValidPrivateGenerationAndCleansOnlyOldOwnedFiles(): void
    {
        $project = $this->tmpPath('parallel-cache');
        mkdir($project . '/config', 0700, true);
        $this->writeImportedConfig($project . '/config/services.php', 'parallel');
        $bootstrap = $project . '/boot.php';
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        file_put_contents($bootstrap, '<?php require ' . var_export($autoload, true) . '; '
            . '$k=new \SymPress\Kernel\Kernel\SiteKernel(__DIR__,"test",false,null,\SymPress\Kernel\WpContext::new()->force(\SymPress\Kernel\WpContext::CORE));'
            . '$c=$k->createContainer();$r=new \SymPress\Kernel\Bundle\BundleRegistry();'
            . 'if(!$k->tryUseRuntimeContainer($c,$r)){$f=$k->configureContainer($c->builder(),$c,$r);$k->createRuntimeContainer($c,$r,$f);}'
            . 'if($c->getParameter("imported.value")!=="parallel")exit(2);');
        $processes = [];
        for ($index = 0; $index < 6; ++$index) {
            $process = new Process([PHP_BINARY, $bootstrap]);
            $process->start();
            $processes[] = $process;
        }
        foreach ($processes as $process) {
            self::assertSame(0, $process->wait(), $process->getErrorOutput());
        }
        $directory = $project . '/var/cache/test/kernel';
        $files = glob($directory . '/container_*.php') ?: [];
        self::assertCount(1, $files);
        $active = $files[0];
        for ($index = 0; $index < 6; ++$index) {
            $file = $directory . '/container_' . sprintf('%016x', $index) . '.php';
            file_put_contents($file, '<?php');
            touch($file, time() - 7200 - $index);
        }
        $unowned = $directory . '/container_unowned.php';
        file_put_contents($unowned, '<?php');
        touch($unowned, time() - 7200);
        $manager = new ContainerCacheManager($directory, false, new ContainerResourceFingerprinter($project, 'test', false));
        $cleanup = new \ReflectionMethod($manager, 'cleanupContainers');
        $lock = fopen($directory . '/container.lock', 'r+');
        flock($lock, LOCK_EX);
        try {
            $cleanup->invoke($manager, $active);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        self::assertFileExists($active);
        self::assertFileExists($unowned);
        self::assertCount(3, array_filter(glob($directory . '/container_*.php') ?: [], static fn (string $file): bool => preg_match('/container_[a-f0-9]{16}\.php$/', $file) === 1));
    }

    public function testCorruptMetadataAndFailedRecompileCannotServeTheOldContainer(): void
    {
        $project = $this->tmpPath('corrupt-cache');
        mkdir($project . '/config', 0700, true);
        $file = $project . '/config/services.php';
        $this->writeImportedConfig($file, 'first');
        $kernel = $this->kernel($project);
        $container = $kernel->createContainer();
        $files = $kernel->configureContainer($container->builder(), $container, new BundleRegistry());
        $kernel->createRuntimeContainer($container, new BundleRegistry(), $files);
        file_put_contents($project . '/var/cache/test/kernel/meta.php', '<?php broken syntax');
        self::assertFalse($kernel->tryUseRuntimeContainer($kernel->createContainer(), new BundleRegistry()));
        file_put_contents($file, '<?php throw new \RuntimeException("configuration failure");');
        try {
            $kernel->configureContainer($kernel->createContainer()->builder(), $kernel->createContainer(), new BundleRegistry());
            self::fail('Compilation failure must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('configuration failure', $exception->getMessage());
        }
        self::assertFalse($kernel->tryUseRuntimeContainer($kernel->createContainer(), new BundleRegistry()));
    }

    public function testMalformedRememberedResourceMapsRebuildValidConfiguration(): void
    {
        $project = $this->tmpPath('malformed-resources');
        mkdir($project . '/config', 0700, true);
        $this->writeImportedConfig($project . '/config/services.php', 'valid');
        $kernel = $this->kernel($project);
        $registry = new BundleRegistry();
        $container = $kernel->createContainer();
        $files = $kernel->configureContainer($container->builder(), $container, $registry);
        $kernel->createRuntimeContainer($container, $registry, $files);
        $path = $project . '/var/cache/test/kernel/meta.php';
        foreach ([[0 => 'malformed'], ['invalid' => ['nested']]] as $resources) {
            $metadata = require $path;
            $metadata['config_resources'] = $resources;
            file_put_contents($path, '<?php return ' . var_export($metadata, true) . ';');
            $rebuilt = $kernel->createContainer();
            self::assertFalse($kernel->tryUseRuntimeContainer($rebuilt, $registry));
            $files = $kernel->configureContainer($rebuilt->builder(), $rebuilt, $registry);
            $kernel->createRuntimeContainer($rebuilt, $registry, $files);
            self::assertSame('valid', $rebuilt->getParameter('imported.value'));
            self::assertTrue($kernel->tryUseRuntimeContainer($kernel->createContainer(), $registry));
        }
        $_SERVER['SYMPRESS_KERNEL_IMMUTABLE_CACHE'] = '1';
        $_SERVER['SYMPRESS_KERNEL_BUILD_ID'] = 'immutable-review';
        try {
            $fingerprinter = new ContainerResourceFingerprinter($project, 'test', false);
            self::assertFalse($fingerprinter->configResourcesAreFresh([0 => 'malformed']));
            self::assertSame([], $fingerprinter->refreshConfigResources([0 => 'malformed']));
        } finally {
            unset($_SERVER['SYMPRESS_KERNEL_IMMUTABLE_CACHE'], $_SERVER['SYMPRESS_KERNEL_BUILD_ID']);
        }
    }

    public function testImmutablePolicyRequiresAndInvalidatesWithBuildIdentity(): void
    {
        $project = $this->tmpPath('immutable-cache');
        mkdir($project . '/config', 0700, true);
        $file = $project . '/config/services.php';
        $this->writeImportedConfig($file, 'first');
        $_SERVER['SYMPRESS_KERNEL_IMMUTABLE_CACHE'] = '1';
        try {
            $fingerprints = new ContainerResourceFingerprinter($project, 'test', false);
            try {
                $fingerprints->fingerprint(new BundleRegistry(), [$file]);
                self::fail('An explicit build identity is required.');
            } catch (\RuntimeException) {
                self::assertTrue(true);
            }
            $_SERVER['SYMPRESS_KERNEL_BUILD_ID'] = 'build-one';
            $first = $fingerprints->fingerprint(new BundleRegistry(), [$file]);
            $this->writeImportedConfig($file, 'other');
            self::assertSame($first, $fingerprints->fingerprint(new BundleRegistry(), [$file]));
            $_SERVER['SYMPRESS_KERNEL_BUILD_ID'] = 'build-two';
            self::assertNotSame($first, $fingerprints->fingerprint(new BundleRegistry(), [$file]));
        } finally {
            unset($_SERVER['SYMPRESS_KERNEL_IMMUTABLE_CACHE'], $_SERVER['SYMPRESS_KERNEL_BUILD_ID']);
        }
    }

    public function testCacheDirectoryReadsRealEnvironmentWithServerAndEnvPrecedence(): void
    {
        $project = $this->tmpPath('env-cache');
        $previous = getenv('APP_CACHE_DIR');
        putenv('APP_CACHE_DIR=' . $project . '/external');
        try {
            $resolver = new KernelConfigurationResolver($project, 'test', new TestSiteConfig('test'));
            self::assertSame($project . '/external/test/kernel', $resolver->cacheDir());
            $_ENV['APP_CACHE_DIR'] = $project . '/env';
            self::assertSame($project . '/env/test/kernel', $resolver->cacheDir());
            $_SERVER['APP_CACHE_DIR'] = $project . '/server';
            self::assertSame($project . '/server/test/kernel', $resolver->cacheDir());
        } finally {
            unset($_SERVER['APP_CACHE_DIR'], $_ENV['APP_CACHE_DIR']);
            putenv($previous === false ? 'APP_CACHE_DIR' : 'APP_CACHE_DIR=' . $previous);
        }
    }

    public function testBundleExtensionStateIsIsolatedAcrossContainerBuilders(): void
    {
        $directory = $this->tmpPath('extension-state');
        mkdir($directory, 0700);
        file_put_contents($directory . '/composer.json', '{}');
        $bundle = new class extends AbstractBundle {
            public function __construct()
            {
                $this->extension = new SingleLoadExtension();
            }
        };
        $registry = $this->registryWithBundle($directory, $bundle);
        foreach ([1, 2] as $iteration) {
            $kernel = $this->kernel($this->tmpPath('extension-project-' . $iteration));
            $container = $kernel->createContainer();
            $files = $kernel->configureContainer($container->builder(), $container, $registry);
            $container->builder()->loadFromExtension('stateful');
            $kernel->createRuntimeContainer($container, $registry, $files);
            $container->builder()->compile();
            self::assertNotSame($bundle->getContainerExtension(), $container->builder()->getExtension('stateful'));
            self::assertTrue($container->getParameter('extension.loaded'));
        }
    }

    public function testRuntimeCompilationPreservesSourceServiceLocatorGraphForLint(): void
    {
        $kernel = $this->kernel($this->tmpPath('definition-graph'));
        $container = $kernel->createContainer();
        $registry = new BundleRegistry();
        $files = $kernel->configureContainer($container->builder(), $container, $registry);
        $container->builder()->register('private.graph.item', \stdClass::class);
        $container->builder()->register('graph.consumer', DefinitionGraphConsumer::class)
            ->setArguments([new ServiceLocatorArgument(['item' => new Reference('private.graph.item')])])
            ->setPublic(true);
        $kernel->createRuntimeContainer($container, $registry, $files);
        self::assertInstanceOf(ServiceLocatorArgument::class, $container->builder()->getDefinition('graph.consumer')->getArgument(0));
        $container->builder()->compile();
        self::assertInstanceOf(\stdClass::class, $container->builder()->get('graph.consumer')->locator->get('item'));
    }

    public function testPackageDescriptorsReuseAcrossRequestsAndInvalidateConsumedContent(): void
    {
        $_SERVER['SYMPRESS_KERNEL_CONTENT_HASHES'] = '1';
        $project = $this->tmpPath('descriptor-cache');
        mkdir($project, 0700);
        file_put_contents($project . '/composer.json', '{}');
        $input = $project . '/bundle-composer.json';
        file_put_contents($input, '{"name":"sympress/one"}');
        $stamp = filemtime($input);
        $cache = new KernelPackageManifestCache($project, 'test', ['sympress/']);
        $descriptors = [$input => ['name' => 'sympress/one', 'type' => 'library', 'extra' => ['kernel' => ['bundle' => 'Example']]]];
        $cache->write(['sympress/one'], $descriptors, [$input => ResourceFingerprint::file($input)]);
        $nextRequest = new KernelPackageManifestCache($project, 'test', ['sympress/']);
        self::assertSame(['sympress/one'], $nextRequest->read());
        self::assertSame($descriptors, $nextRequest->metadata());
        self::assertSame(0600, fileperms($project . '/var/cache/test/kernel/discovery-packages.php') & 0777);
        file_put_contents($input, '{"name":"sympress/two"}');
        touch($input, $stamp);
        self::assertNull((new KernelPackageManifestCache($project, 'test', ['sympress/']))->read());
    }

    public function testRuntimeWriterWaitsForExistingReadersBeforePublishing(): void
    {
        $project = $this->tmpPath('reader-writer-lock');
        $kernel = $this->kernel($project);
        $container = $kernel->createContainer();
        $files = $kernel->configureContainer($container->builder(), $container, new BundleRegistry());
        $kernel->createRuntimeContainer($container, new BundleRegistry(), $files);
        $lockFile = $project . '/var/cache/test/kernel/container.lock';
        $reader = new Process([PHP_BINARY, '-r', '$h=fopen($argv[1],"r");flock($h,LOCK_SH);echo "READY";usleep(400000);flock($h,LOCK_UN);', $lockFile]);
        $reader->start();
        self::assertTrue($reader->waitUntil(static fn (string $type, string $buffer): bool => str_contains($buffer, 'READY')));
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $script = 'require $argv[1];$_SERVER["SYMPRESS_KERNEL_BUILD_ID"]="reader-race";'
            . '$k=new \SymPress\Kernel\Kernel\SiteKernel($argv[2],"test",false,null,\SymPress\Kernel\WpContext::new()->force(\SymPress\Kernel\WpContext::CORE));'
            . '$c=$k->createContainer();$r=new \SymPress\Kernel\Bundle\BundleRegistry();'
            . '$f=$k->configureContainer($c->builder(),$c,$r);$k->createRuntimeContainer($c,$r,$f);';
        $writer = new Process([PHP_BINARY, '-r', $script, $autoload, $project]);
        $started = microtime(true);
        $writer->start();
        self::assertSame(0, $reader->wait(), $reader->getErrorOutput());
        self::assertSame(0, $writer->wait(), $writer->getErrorOutput());
        self::assertGreaterThan(0.25, microtime(true) - $started);
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
}
