<?php

declare(strict_types=1);

namespace SymPress\Kernel\Routing;

use SymPress\Kernel\Attribute\Route as KernelRouteAttribute;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class RouteCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(RouteLoader::class)) {
            return;
        }

        $environmentParameter = $container->hasParameter('kernel.environment')
            ? $container->getParameter('kernel.environment')
            : null;
        $environment = is_scalar($environmentParameter) || $environmentParameter instanceof \Stringable
            ? (string) $environmentParameter
            : null;
        $frontendRoutes = [];
        $restRoutes = [];
        $serviceMap = [];

        foreach ($container->getDefinitions() as $id => $definition) {
            if (!$definition->hasTag(RouteLoader::TAG)) {
                continue;
            }

            $class = $this->controllerClass($container, $id, $definition->getClass());

            if ($class === null) {
                continue;
            }

            [$controllerFrontendRoutes, $controllerRestRoutes] = $this->compiledRoutesForController(
                $class,
                $id,
                $environment,
            );

            if ($controllerFrontendRoutes === [] && $controllerRestRoutes === []) {
                continue;
            }

            $frontendRoutes = array_merge($frontendRoutes, $controllerFrontendRoutes);
            $restRoutes = array_merge($restRoutes, $controllerRestRoutes);
            $serviceMap[$id] = new Reference($id);
        }

        $definition = $container->getDefinition(RouteLoader::class);
        $definition->setArgument(0, ServiceLocatorTagPass::register($container, $serviceMap));
        $definition->setArgument(1, $frontendRoutes);
        $definition->setArgument(2, $restRoutes);
    }

    /**
     * @return array{
     *     list<array{
     *         name: string,
     *         path: string,
     *         methods: list<string>,
     *         schemes: list<string>,
     *         host: string,
     *         defaults: array<string, mixed>,
     *         requirements: array<string, string>,
     *         options: array<string, mixed>,
     *         condition: string,
     *         priority: int,
     *         service: string,
     *         class: string,
     *         method: string
     *     }>,
     *     list<array{
     *         name: string,
     *         path: string,
     *         methods: list<string>,
     *         schemes: list<string>,
     *         host: string,
     *         defaults: array<string, mixed>,
     *         requirements: array<string, string>,
     *         options: array<string, mixed>,
     *         condition: string,
     *         priority: int,
     *         service: string,
     *         class: string,
     *         method: string,
     *         rest: array{
     *             namespace: string,
     *             path: ?string,
     *             args: array<string, array<string, mixed>>,
     *             permission_callback: mixed,
     *             public: bool,
     *             show_in_index: ?bool,
     *             override: bool
     *         }
     *     }>
     * }
     */
    private function compiledRoutesForController(string $class, string $serviceId, ?string $environment): array
    {
        $frontendRoutes = [];
        $restRoutes = [];

        foreach ($this->routeCollections($class, $serviceId, $environment) as $collection) {
            foreach ($collection->all() as $name => $route) {
                $definition = $this->definitionFromRoute($collection, $name, $route);

                if ($this->isRestRoute($route)) {
                    $definition['rest'] = $this->restDefinition($name, $route);
                    $restRoutes[] = $definition;

                    continue;
                }

                $frontendRoutes[] = $definition;
            }
        }

        return [$frontendRoutes, $restRoutes];
    }

    /** @return list<RouteCollection> */
    private function routeCollections(string $class, string $serviceId, ?string $environment): array
    {
        $collections = [
            (new AttributeRouteMetadataLoader(KernelRouteAttribute::class, $environment))
                ->loadController($class, $serviceId),
        ];

        $symfonyRouteAttribute = 'Symfony\Component\Routing\Attribute\Route';

        if (class_exists($symfonyRouteAttribute)) {
            $collections[] = (new AttributeRouteMetadataLoader($symfonyRouteAttribute, $environment))
                ->loadController($class, $serviceId);
        }

        return $collections;
    }

    private function controllerClass(ContainerBuilder $container, string $serviceId, ?string $class): ?string
    {
        $className = is_string($class) && $class !== '' ? $class : $serviceId;

        if (!class_exists($className)) {
            return null;
        }

        $reflection = $container->getReflectionClass($className, false);

        if (!$reflection instanceof \ReflectionClass) {
            return null;
        }

        if ($reflection->isAbstract()) {
            throw new InvalidArgumentException(sprintf('Route controller "%s" must not be abstract.', $className));
        }

        return $reflection->getName();
    }

    /**
     * @return array{
     *     name: string,
     *     path: string,
     *     methods: list<string>,
     *     schemes: list<string>,
     *     host: string,
     *     defaults: array<string, mixed>,
     *     requirements: array<string, string>,
     *     options: array<string, mixed>,
     *     condition: string,
     *     priority: int,
     *     service: string,
     *     class: string,
     *     method: string
     * }
     */
    private function definitionFromRoute(RouteCollection $collection, string $name, Route $route): array
    {
        $service = $route->getDefault('_controller_service');
        $class = $route->getDefault('_controller_class');
        $method = $route->getDefault('_controller_method');

        if (!is_string($service) || $service === '' || !is_string($class) || !is_string($method)) {
            throw new InvalidArgumentException(sprintf('Route "%s" does not define a valid controller.', $name));
        }

        return [
            'name'         => $name,
            'path'         => $route->getPath(),
            'methods'      => $this->stringList($route->getMethods()),
            'schemes'      => $this->stringList($route->getSchemes()),
            'host'         => $route->getHost(),
            'defaults'     => $this->stringKeyMap($route->getDefaults()),
            'requirements' => $this->stringMap($route->getRequirements()),
            'options'      => $this->stringKeyMap($route->getOptions()),
            'condition'    => $route->getCondition(),
            'priority'     => $collection->getPriority($name) ?? 0,
            'service'      => $service,
            'class'        => $class,
            'method'       => $method,
        ];
    }

    private function isRestRoute(Route $route): bool
    {
        $format = $route->getDefault('_format');

        return is_string($format) && strtolower($format) === 'json';
    }

    /**
     * @return array{
     *     namespace: string,
     *     path: ?string,
     *     args: array<string, array<string, mixed>>,
     *     permission_callback: mixed,
     *     public: bool,
     *     show_in_index: ?bool,
     *     override: bool
     * }
     */
    private function restDefinition(string $name, Route $route): array
    {
        $options = $route->getOptions();
        $namespace = $options['rest_namespace'] ?? $options['namespace'] ?? null;

        if (!is_string($namespace) || $namespace === '') {
            throw new InvalidArgumentException(
                sprintf('REST route "%s" must define a non-empty "rest_namespace" route option.', $name),
            );
        }

        $args = $this->restArgs($options['args'] ?? []);

        $permissionCallback = $options['permission_callback'] ?? null;
        $public = ($options['public'] ?? false) === true;

        if ($permissionCallback === null && !$public) {
            throw new InvalidArgumentException(
                sprintf(
                    'REST route "%s" must define "permission_callback" or set route option "public" to true.',
                    $name,
                ),
            );
        }

        return [
            'namespace'           => $namespace,
            'path'                => is_string($options['rest_path'] ?? null) ? $options['rest_path'] : null,
            'args'                => $args,
            'permission_callback' => $permissionCallback,
            'public'              => $public,
            'show_in_index'       => is_bool($options['show_in_index'] ?? null) ? $options['show_in_index'] : null,
            'override'            => (bool) ($options['override'] ?? false),
        ];
    }

    /**
     * @param array<mixed, mixed> $values
     * @return array<string, mixed>
     */
    private function stringKeyMap(array $values): array
    {
        $map = [];

        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $map[$key] = $value;
        }

        return $map;
    }

    /**
     * @param array<mixed, mixed> $values
     * @return array<string, string>
     */
    private function stringMap(array $values): array
    {
        $map = [];

        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (!is_scalar($value) && !$value instanceof \Stringable) {
                continue;
            }

            $map[$key] = (string) $value;
        }

        return $map;
    }

    /** @return list<string> */
    private function stringList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        return array_values(
            array_filter(
                $values,
                static fn (mixed $value): bool => is_string($value),
            ),
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function restArgs(mixed $args): array
    {
        if (!is_array($args)) {
            return [];
        }

        $normalized = [];

        foreach ($args as $name => $definition) {
            if (!is_string($name) || !is_array($definition)) {
                continue;
            }

            $normalized[$name] = $this->stringKeyMap($definition);
        }

        return $normalized;
    }
}
