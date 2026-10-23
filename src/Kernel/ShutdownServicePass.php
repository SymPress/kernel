<?php

declare(strict_types=1);

namespace SymPress\Kernel\Kernel;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Contracts\Service\ShutdownInterface;

/** @internal Adds the native flag to existing ResetInterface autoconfiguration. */
final class ShutdownServicePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!interface_exists(ShutdownInterface::class)) {
            return;
        }

        /** @var array<string, list<array<string, mixed>>> $taggedServices */
        $taggedServices = $container->findTaggedServiceIds('kernel.reset');
        foreach ($taggedServices as $id => $tags) {
            $definition = $container->getDefinition($id);
            $class = $container->getParameterBag()->resolveValue($definition->getClass());
            if (!is_string($class) || !is_a($class, ShutdownInterface::class, true)) {
                continue;
            }

            $definition->clearTag('kernel.reset');
            foreach ($this->normalizeTags($tags) as $attributes) {
                $definition->addTag('kernel.reset', $attributes);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $tags
     * @return list<array<string, mixed>>
     */
    private function normalizeTags(array $tags): array
    {
        $explicitReset = false;
        foreach ($tags as $attributes) {
            $explicitReset = $explicitReset || (($attributes['method'] ?? null) === 'reset'
                && isset($attributes['on_shutdown']));
        }

        $registered = [];
        foreach ($tags as $attributes) {
            if (($attributes['method'] ?? null) === 'reset') {
                if ($explicitReset && !isset($attributes['on_shutdown'])) {
                    continue;
                }
                $attributes['on_shutdown'] ??= 'flag';
            }
            if (in_array($attributes, $registered, true)) {
                continue;
            }

            $registered[] = $attributes;
        }

        return $registered;
    }
}
