<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

use SymPress\Kernel\Bundle\AbstractBundle;
use SymPress\Kernel\Bundle\BundleMetadata;
use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Kernel\AbstractKernel;
use SymPress\Kernel\Location\Locations;
use SymPress\Kernel\SiteConfig;
use SymPress\Kernel\WpContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ConfigPrecedenceTest extends TestCase
{
    /**
     * @var array<int, string>
     */
    private array $paths = [];

    public function testKernelLogsDirPointsToProjectVarLog(): void
    {
        $projectDir = $this->tmpPath('project');
        $kernel = new class($projectDir, 'test', false, $this->config(), WpContext::new()->force(WpContext::CORE)) extends AbstractKernel {
        };
        $container = $kernel->createContainer();

        $kernel->configureContainer($container->builder(), $container, new BundleRegistry());

        self::assertSame($projectDir . '/var/cache/test/kernel', $container->builder()->getParameter('kernel.cache_dir'));
        self::assertSame($projectDir . '/var/log', $container->builder()->getParameter('kernel.logs_dir'));
    }

    protected function tearDown(): void
    {
        if ($this->paths === []) {
            return;
        }

        (new Filesystem())->remove($this->paths);
        $this->paths = [];
    }

    public function testSiteConfigOverridesBundleConfig(): void
    {
        $projectDir = $this->tmpPath('project');
        $bundleDir = $this->tmpPath('bundle');
        $this->writePhpConfig("{$bundleDir}/config/services.php", 'bundle');
        $this->writePhpConfig("{$projectDir}/config/services.php", 'site');
        file_put_contents("{$bundleDir}/composer.json", '{}');

        $bundle = new class($bundleDir) extends AbstractBundle {
            public function __construct(
                private readonly string $bundlePath,
            ) {
            }

            public function path(): string
            {
                return $this->bundlePath;
            }
        };
        $registry = (new BundleRegistry())->add(
            new BundleMetadata(
                'sympress/test-bundle',
                'wordpress-plugin',
                'test-bundle/test-bundle.php',
                $bundleDir,
                "{$bundleDir}/composer.json",
                $bundle,
            ),
        );
        $kernel = new class($projectDir, 'test', false, $this->config(), WpContext::new()->force(WpContext::CORE)) extends AbstractKernel {
        };
        $container = $kernel->createContainer();
        $loaded = $kernel->configureContainer($container->builder(), $container, $registry);

        self::assertSame('site', $container->builder()->getParameter('demo.value'));
        self::assertContains("{$bundleDir}/config/services.php", $loaded);
        self::assertContains("{$projectDir}/config/services.php", $loaded);
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
PHP,
                $value,
            ),
        );
    }

    private function config(): SiteConfig
    {
        $locations = $this->createMock(Locations::class);

        return new class($locations) implements SiteConfig {
            public function __construct(
                private readonly Locations $locations,
            ) {
            }

            public function locations(): Locations
            {
                return $this->locations;
            }

            public function hosting(): string
            {
                return self::HOSTING_OTHER;
            }

            public function hostingIs(string $hosting): bool
            {
                return $this->hosting() === $hosting;
            }

            public function env(): string
            {
                return 'test';
            }

            public function envIs(string $env): bool
            {
                return $this->env() === $env;
            }

            public function get(string $name, mixed $default = null): mixed
            {
                return $default;
            }

            public function jsonSerialize(): array
            {
                return [];
            }
        };
    }
}
