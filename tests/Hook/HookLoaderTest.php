<?php

declare(strict_types=1);

namespace {
    if (!function_exists('add_action')) {
        function add_action(
            string $hook,
            callable $callback,
            int $priority = 10,
            int $acceptedArgs = 1,
        ): void {
            $GLOBALS['kernel_test_actions'][] = [
                'hook' => $hook,
                'callback' => $callback,
                'priority' => $priority,
                'acceptedArgs' => $acceptedArgs,
            ];
        }
    }

    if (!function_exists('add_filter')) {
        function add_filter(
            string $hook,
            callable $callback,
            int $priority = 10,
            int $acceptedArgs = 1,
        ): void {
            $GLOBALS['kernel_test_filters'][] = [
                'hook' => $hook,
                'callback' => $callback,
                'priority' => $priority,
                'acceptedArgs' => $acceptedArgs,
            ];
        }
    }
}

namespace SymPress\Kernel\Tests\Hook {
    use SymPress\Kernel\Hook\HookLoader;
    use PHPUnit\Framework\TestCase;
    use Psr\Container\ContainerInterface;

    final class HookLoaderTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['kernel_test_actions'] = [];
            $GLOBALS['kernel_test_filters'] = [];
        }

        public function testHookServicesAreResolvedLazily(): void
        {
            $service = new LazyHookService();
            $container = new LazyHookContainer(['lazy' => $service]);
            $loader = new HookLoader(
                $container,
                [
                    [
                        'service' => 'lazy',
                        'hook' => 'init',
                        'method' => 'handle',
                        'type' => 'action',
                        'priority' => 20,
                        'accepted_args' => 1,
                    ],
                ],
            );

            $loader->register();

            self::assertSame(0, $container->gets);
            self::assertCount(1, $GLOBALS['kernel_test_actions']);
            self::assertSame('init', $GLOBALS['kernel_test_actions'][0]['hook']);

            $GLOBALS['kernel_test_actions'][0]['callback']('payload');

            self::assertSame(1, $container->gets);
            self::assertSame(['payload'], $service->received);
        }

        public function testFilterCallbackReturnsServiceValue(): void
        {
            $container = new LazyHookContainer(['lazy' => new LazyHookService()]);
            $loader = new HookLoader(
                $container,
                [
                    [
                        'service' => 'lazy',
                        'hook' => 'the_content',
                        'method' => 'filter',
                        'type' => 'filter',
                        'priority' => 10,
                        'accepted_args' => 1,
                    ],
                ],
            );

            $loader->register();

            $callback = $GLOBALS['kernel_test_filters'][0]['callback'];

            self::assertSame('filtered content', $callback('content'));
        }
    }

    final class LazyHookService
    {
        /**
         * @var list<mixed>
         */
        public array $received = [];

        public function handle(mixed $value): void
        {
            $this->received[] = $value;
        }

        public function filter(string $value): string
        {
            return sprintf('filtered %s', $value);
        }
    }

    final class LazyHookContainer implements ContainerInterface
    {
        public int $gets = 0;

        /**
         * @param array<string, object> $services
         */
        public function __construct(
            private readonly array $services,
        ) {
        }

        public function get(string $id): mixed
        {
            ++$this->gets;

            return $this->services[$id];
        }

        public function has(string $id): bool
        {
            return array_key_exists($id, $this->services);
        }
    }
}
