<?php

declare(strict_types=1);

namespace SymPress\Kernel\Hook;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

final class HookCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(HookLoader::class)) {
            return;
        }

        $hooks = [];
        $serviceMap = [];

        foreach ($container->getDefinitions() as $id => $definition) {
            if (!$definition->hasTag(HookLoader::TAG)) {
                continue;
            }

            $serviceMap[$id] = new Reference($id);

            foreach ($definition->getTag(HookLoader::TAG) as $attributes) {
                $method = $this->optionalStringAttribute($attributes, 'method', '__invoke');
                $this->validateHookMethod($container, $id, $definition->getClass(), $method);

                $hooks[] = [
                    'service' => $id,
                    'hook' => $this->stringAttribute($attributes, 'hook', $id),
                    'method' => $method,
                    'type' => $this->hookType($attributes, $id),
                    'priority' => $this->intAttribute($attributes, 'priority', 10),
                    'accepted_args' => $this->acceptedArgs(
                        $container,
                        $id,
                        $definition->getClass(),
                        $method,
                        $attributes,
                    ),
                ];
            }
        }

        $definition = $container->getDefinition(HookLoader::class);
        $definition->setArgument(0, ServiceLocatorTagPass::register($container, $serviceMap));
        $definition->setArgument(1, $hooks);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function stringAttribute(array $attributes, string $name, string $serviceId): string
    {
        $value = $attributes[$name] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        throw new InvalidArgumentException(
            sprintf('Service "%s" must define a non-empty "%s" hook attribute.', $serviceId, $name),
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function optionalStringAttribute(array $attributes, string $name, string $default): string
    {
        $value = $attributes[$name] ?? null;

        if (!is_string($value) || $value === '') {
            return $default;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function intAttribute(array $attributes, string $name, int $default): int
    {
        $value = $attributes[$name] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidArgumentException(
            sprintf('Hook attribute "%s" must be an integer.', $name),
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function acceptedArgsAttribute(array $attributes): int
    {
        $acceptedArgs = $this->intAttribute($attributes, 'accepted_args', 1);

        if ($acceptedArgs >= 0) {
            return $acceptedArgs;
        }

        throw new InvalidArgumentException(
            'Hook attribute "accepted_args" must be greater than or equal to 0.',
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function hookType(array $attributes, string $serviceId): string
    {
        $type = strtolower($this->optionalStringAttribute($attributes, 'type', 'action'));

        if (in_array($type, ['action', 'filter'], true)) {
            return $type;
        }

        throw new InvalidArgumentException(
            sprintf('Service "%s" uses unsupported hook type "%s".', $serviceId, $type),
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function acceptedArgs(
        ContainerBuilder $container,
        string $serviceId,
        ?string $class,
        string $method,
        array $attributes,
    ): int {
        if (isset($attributes['accepted_args'])) {
            return $this->acceptedArgsAttribute($attributes);
        }

        $reflectionMethod = $this->reflectHookMethod($container, $serviceId, $class, $method);

        if (!$reflectionMethod instanceof \ReflectionMethod) {
            return 1;
        }

        return $reflectionMethod->getNumberOfParameters();
    }

    private function validateHookMethod(
        ContainerBuilder $container,
        string $serviceId,
        ?string $class,
        string $method,
    ): void {
        $reflectionMethod = $this->reflectHookMethod($container, $serviceId, $class, $method);

        if (!$reflectionMethod instanceof \ReflectionMethod) {
            return;
        }

        if ($reflectionMethod->isPublic()) {
            return;
        }

        throw new InvalidArgumentException(
            sprintf('Hook method "%s::%s" must be public.', $reflectionMethod->class, $method),
        );
    }

    private function reflectHookMethod(
        ContainerBuilder $container,
        string $serviceId,
        ?string $class,
        string $method,
    ): ?\ReflectionMethod {
        $className = is_string($class) && $class !== '' ? $class : $serviceId;

        if (!class_exists($className)) {
            return null;
        }

        $reflection = $container->getReflectionClass($className, false);

        if (!$reflection instanceof \ReflectionClass || !$reflection->hasMethod($method)) {
            throw new InvalidArgumentException(
                sprintf('Service "%s" does not define hook method "%s".', $serviceId, $method),
            );
        }

        return $reflection->getMethod($method);
    }
}
