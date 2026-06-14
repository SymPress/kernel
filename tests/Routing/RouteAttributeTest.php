<?php

declare(strict_types=1);

namespace {
    if (!function_exists('register_rest_route')) {
        function register_rest_route(
            string $namespace,
            string $route,
            array $args = [],
            bool $override = false,
        ): bool {
            $GLOBALS['kernel_test_rest_routes'][] = [
                'namespace' => $namespace,
                'route'     => $route,
                'args'      => $args,
                'override'  => $override,
            ];

            return true;
        }
    }
}

namespace SymPress\Kernel\Tests\Routing {
    use PHPUnit\Framework\TestCase;
    use SymPress\Kernel\Bundle\AbstractBundle;
    use SymPress\Kernel\Bundle\BundleMetadata;
    use SymPress\Kernel\Bundle\BundleRegistry;
    use SymPress\Kernel\Container;
    use SymPress\Kernel\Kernel\AbstractKernel;
    use SymPress\Kernel\Routing\RouteLoader;
    use SymPress\Kernel\Tests\Fixtures\RouteBundle\Src\FakeRestRequest;
    use SymPress\Kernel\Tests\Support\TestSiteConfig;
    use SymPress\Kernel\WpContext;
    use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
    use Symfony\Component\Filesystem\Filesystem;
    use Symfony\Component\HttpFoundation\Request;

    final class RouteAttributeTest extends TestCase
    {
        /** @var list<string> */
        private array $paths = [];

        protected function setUp(): void
        {
            $GLOBALS['kernel_test_rest_routes'] = [];
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['kernel_test_rest_routes']);

            if ($this->paths === []) {
                return;
            }

            (new Filesystem())->remove($this->paths);
            $this->paths = [];
        }

        public function testFrontendRoutesAreMatchedAndControllersAreResolvedLazily(): void
        {
            $loader = $this->compileFixture()->get(RouteLoader::class);

            self::assertInstanceOf(RouteLoader::class, $loader);

            $response = $loader->handle(Request::create('/kernel/hello/codex', 'GET'));

            self::assertNotNull($response);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('codex:GET', $response->getContent());

            $methodMismatch = $loader->handle(Request::create('/kernel/hello/codex', 'POST'));

            self::assertNotNull($methodMismatch);
            self::assertSame(405, $methodMismatch->getStatusCode());
            self::assertSame('GET', $methodMismatch->headers->get('Allow'));
        }

        public function testRestRoutesAreRegisteredWithWordPressRestApi(): void
        {
            $loader = $this->compileFixture()->get(RouteLoader::class);

            self::assertInstanceOf(RouteLoader::class, $loader);

            $loader->registerRestRoutes();

            self::assertCount(1, $GLOBALS['kernel_test_rest_routes']);

            $route = $GLOBALS['kernel_test_rest_routes'][0];

            self::assertSame('kernel-fixture/v1', $route['namespace']);
            self::assertStringStartsWith('/tools/items/', $route['route']);
            self::assertStringContainsString('(?P<id>', $route['route']);
            self::assertSame(['GET'], $route['args']['methods']);
            self::assertSame(['required' => true], $route['args']['args']['id']);
            self::assertFalse($route['override']);

            $request = new FakeRestRequest([
                'allowed' => true,
                'id'      => '42',
                'route'   => 'rest',
            ]);

            self::assertTrue($route['args']['permission_callback']($request));
            self::assertSame(
                [
                    'id'    => '42',
                    'route' => 'rest',
                ],
                $route['args']['callback']($request),
            );
        }

        public function testRestRoutesWithoutPermissionCallbackFailClosed(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('must define "permission_callback"');

            $this->compileFixture([MissingPermissionRestController::class]);
        }

        public function testRestRoutesCanBeExplicitlyPublic(): void
        {
            $loader = $this->compileFixture([PublicRestController::class])->get(RouteLoader::class);

            self::assertInstanceOf(RouteLoader::class, $loader);

            $loader->registerRestRoutes();

            $route = $this->registeredRestRoute('kernel-public/v1');

            self::assertTrue($route['args']['permission_callback'](new FakeRestRequest([])));
        }

        public function testRestRoutesCanUseExplicitWordPressPath(): void
        {
            $loader = $this->compileFixture([ExplicitRestPathController::class])->get(RouteLoader::class);

            self::assertInstanceOf(RouteLoader::class, $loader);

            $loader->registerRestRoutes();

            $route = $this->registeredRestRoute('kernel-explicit/v1');

            self::assertSame('/stable/items/(?P<slug>[a-z0-9-]+)', $route['route']);
        }

        public function testKernelHandleDispatchesRoutesWithoutHttpKernelService(): void
        {
            [$kernel] = $this->compileFixtureKernel();

            $response = $kernel->handle(Request::create('/kernel/hello/codex', 'GET'));

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('codex:GET', $response->getContent());
        }

        /** @param list<class-string> $extraControllers */
        private function compileFixture(array $extraControllers = []): Container
        {
            return $this->compileFixtureKernel($extraControllers)[1];
        }

        /**
         * @param list<class-string> $extraControllers
         * @return array{AbstractKernel, Container}
         */
        private function compileFixtureKernel(array $extraControllers = []): array
        {
            $fixturePath = dirname(__DIR__) . '/Fixtures/RouteBundle';
            $registry = (new BundleRegistry())->add(
                new BundleMetadata(
                    'sympress/route-bundle',
                    'wordpress-plugin',
                    'route-bundle/route-bundle.php',
                    $fixturePath,
                    sprintf('%s/composer.json', $fixturePath),
                    new class ($fixturePath) extends AbstractBundle {
                        public function __construct(
                            private readonly string $fixturePath,
                        ) {
                        }

                        public function path(): string
                        {
                            return $this->fixturePath;
                        }
                    },
                ),
            );
            $kernel = new class (
                $this->tmpPath('route-project'),
                'test',
                false,
                new TestSiteConfig('test'),
                WpContext::new()->force(WpContext::CORE),
            ) extends AbstractKernel {
            };
            $container = $kernel->createContainer();
            $loaded = $kernel->configureContainer($container->builder(), $container, $registry);

            foreach ($extraControllers as $controller) {
                $container->builder()
                    ->register($controller, $controller)
                    ->addTag(RouteLoader::TAG);
            }

            $kernel->createRuntimeContainer($container, $registry, $loaded);

            return [$kernel, $container];
        }

        private function tmpPath(string $prefix): string
        {
            $path = sprintf('%s/%s-%s', sys_get_temp_dir(), $prefix, uniqid('', true));
            $this->paths[] = $path;

            return $path;
        }

        /** @return array{namespace: string, route: string, args: array<string, mixed>, override: bool} */
        private function registeredRestRoute(string $namespace): array
        {
            foreach ($GLOBALS['kernel_test_rest_routes'] as $route) {
                if (($route['namespace'] ?? null) === $namespace) {
                    return $route;
                }
            }

            self::fail(sprintf('REST route namespace "%s" was not registered.', $namespace));
        }
    }

}
