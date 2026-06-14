<?php

declare(strict_types=1);

namespace SymPress\Kernel\Routing;

use Symfony\Component\Routing\Loader\AttributeClassLoader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class AttributeRouteMetadataLoader extends AttributeClassLoader
{
    private ?string $serviceId = null;

    public function __construct(
        string $attributeClass,
        ?string $environment,
    ) {

        parent::__construct($environment);
        $this->setRouteAttributeClass($attributeClass);
    }

    public function loadController(string $class, string $serviceId): RouteCollection
    {
        $this->serviceId = $serviceId;

        try {
            return $this->load($class);
        } finally {
            $this->serviceId = null;
        }
    }

    /** @param \ReflectionClass<object> $class */
    protected function configureRoute(
        Route $route,
        \ReflectionClass $class,
        \ReflectionMethod $method,
        object $attr,
    ): void {

        if (!$method->isPublic()) {
            throw new \InvalidArgumentException(
                sprintf('Route controller method "%s::%s" must be public.', $class->getName(), $method->getName()),
            );
        }

        if ($this->serviceId === null) {
            throw new \LogicException('Cannot configure a route without a controller service id.');
        }

        $route->setDefault('_controller', sprintf('%s::%s', $class->getName(), $method->getName()));
        $route->setDefault('_controller_class', $class->getName());
        $route->setDefault('_controller_service', $this->serviceId);
        $route->setDefault('_controller_method', $method->getName());
    }
}
