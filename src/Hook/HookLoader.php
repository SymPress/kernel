<?php

declare(strict_types=1);

namespace SymPress\Kernel\Hook;

use Psr\Container\ContainerInterface;

final class HookLoader
{
    public const string TAG = 'kernel.hook';

    /**
     * @param array<int, array{
     *     service: string,
     *     hook: string,
     *     method: string,
     *     type: string,
     *     priority: int,
     *     accepted_args: int
     * }> $hooks
     */
    public function __construct(
        private readonly ContainerInterface $services,
        private array $hooks = [],
    ) {
    }

    private bool $registered = false;

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->assertWordPressHooksAvailable();

        foreach ($this->hooks as $hook) {
            $callback = $this->callback($hook['service'], $hook['method']);

            if ($hook['type'] === 'filter') {
                add_filter(
                    $hook['hook'],
                    $callback,
                    $hook['priority'],
                    $hook['accepted_args'],
                );

                continue;
            }

            add_action(
                $hook['hook'],
                $callback,
                $hook['priority'],
                $hook['accepted_args'],
            );
        }

        $this->registered = true;
    }

    private function assertWordPressHooksAvailable(): void
    {
        if (function_exists('add_action') && function_exists('add_filter')) {
            return;
        }

        throw new \RuntimeException('WordPress hook functions are not available.');
    }

    private function callback(string $serviceId, string $method): \Closure
    {
        return function (mixed ...$arguments) use ($serviceId, $method): mixed {
            $service = $this->services->get($serviceId);
            $callback = [$service, $method];

            if (!is_callable($callback)) {
                throw new \RuntimeException(
                    sprintf(
                        'Hook callback "%s::%s" is not callable.',
                        get_debug_type($service),
                        $method,
                    ),
                );
            }

            return $callback(...$arguments);
        };
    }
}
