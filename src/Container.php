<?php

declare(strict_types=1);

namespace SymPress\Kernel;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use SymPress\Kernel\Kernel\KernelInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface as SymfonyContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

final class Container implements SymfonyContainerInterface
{
    public const string APP_ID = 'kernel.app';
    public const string CONFIG_ID = 'kernel.config';
    public const string CONTAINER_ID = 'kernel.container';
    public const string CONTEXT_ID = 'kernel.context';
    public const string KERNEL_ID = 'kernel.kernel';

    private readonly ContainerBuilder $builder;
    private ?PsrContainerInterface $runtimeContainer = null;
    private ?App $app = null;
    private ?KernelInterface $kernel = null;

    /** @var array<int, PsrContainerInterface> */
    private array $containers;

    public function __construct(
        ?SiteConfig $config = null,
        ?WpContext $context = null,
        ?ContainerBuilder $builder = null,
        PsrContainerInterface ...$containers,
    ) {

        $this->config = $config ?? new EnvConfig();
        $this->context = $context ?? WpContext::new()->force(WpContext::CORE);
        $this->builder = $builder ?? new ContainerBuilder();
        $this->containers = $containers;
        $this->bootstrapBuilder();
    }

    private SiteConfig $config;
    private WpContext $context;

    public function withSiteConfig(SiteConfig $config): self
    {
        $instance = clone $this;
        $instance->config = $config;
        $instance->builder->setParameter('kernel.environment', $config->env());
        $instance->hydrateBuilder();

        return $instance;
    }

    public function config(): SiteConfig
    {
        return $this->config;
    }

    public function context(): WpContext
    {
        return $this->context;
    }

    public function builder(): ContainerBuilder
    {
        return $this->builder;
    }

    public function runtimeContainer(): ?PsrContainerInterface
    {
        return $this->runtimeContainer;
    }

    public function setApp(App $app): void
    {
        $this->app = $app;
        $this->registerSynthetic(self::APP_ID, App::class);
        $this->builder->setAlias(App::class, self::APP_ID)->setPublic(true);
        $this->hydrateBuilder();

        if (!$this->runtimeContainer instanceof PsrContainerInterface) {
            return;
        }

        $this->hydrate($this->runtimeContainer);
    }

    public function setKernel(KernelInterface $kernel): void
    {
        $this->kernel = $kernel;
        $this->registerSynthetic(self::KERNEL_ID, KernelInterface::class);
        $this->builder->setAlias(KernelInterface::class, self::KERNEL_ID)->setPublic(true);
        $this->hydrateBuilder();

        if (!$this->runtimeContainer instanceof PsrContainerInterface) {
            return;
        }

        $this->hydrate($this->runtimeContainer);
    }

    public function addContainer(PsrContainerInterface $container): self
    {
        $this->containers[] = $container;

        return $this;
    }

    public function useRuntimeContainer(PsrContainerInterface $runtimeContainer): void
    {
        $this->runtimeContainer = $runtimeContainer;
        $this->hydrate($runtimeContainer);
    }

    public function resetRuntimeContainer(): void
    {
        $this->runtimeContainer = null;
        $this->hydrateBuilder();
    }

    public function hydrateBuilder(): void
    {
        $this->hydrate($this->builder);
    }

    public function set(string $id, ?object $service): void
    {
        if ($this->runtimeContainer instanceof SymfonyContainerInterface) {
            $this->runtimeContainer->set($id, $service);

            return;
        }

        $this->builder->set($id, $service);
    }

    public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
    {
        if ($this->runtimeContainer instanceof PsrContainerInterface) {
            if ($this->runtimeContainer->has($id)) {
                return $this->getFromContainer($this->runtimeContainer, $id, $invalidBehavior);
            }

            foreach ($this->containers as $container) {
                if ($container->has($id)) {
                    return $this->getFromContainer($container, $id, $invalidBehavior);
                }
            }

            return $this->handleMissingService($id, $invalidBehavior);
        }

        if ($this->builder->has($id)) {
            return $this->builder->get($id, $invalidBehavior);
        }

        foreach ($this->containers as $container) {
            if ($container->has($id)) {
                return $this->getFromContainer($container, $id, $invalidBehavior);
            }
        }

        return $this->handleMissingService($id, $invalidBehavior);
    }

    public function has(string $id): bool
    {
        if ($this->runtimeContainer instanceof PsrContainerInterface) {
            if ($this->runtimeContainer->has($id)) {
                return true;
            }

            foreach ($this->containers as $container) {
                if ($container->has($id)) {
                    return true;
                }
            }

            return false;
        }

        if ($this->builder->has($id)) {
            return true;
        }

        foreach ($this->containers as $container) {
            if ($container->has($id)) {
                return true;
            }
        }

        return false;
    }

    public function initialized(string $id): bool
    {
        if ($this->runtimeContainer instanceof SymfonyContainerInterface) {
            return $this->runtimeContainer->initialized($id);
        }

        if ($this->builder->has($id)) {
            return $this->builder->initialized($id);
        }

        return false;
    }

    public function getParameter(string $name): array|bool|string|int|float|\UnitEnum|null
    {
        if ($this->runtimeContainer instanceof SymfonyContainerInterface) {
            return $this->runtimeContainer->getParameter($name);
        }

        if (!$this->builder->hasParameter($name)) {
            throw new ParameterNotFoundException($name);
        }

        return $this->builder->getParameter($name);
    }

    public function hasParameter(string $name): bool
    {
        if ($this->runtimeContainer instanceof SymfonyContainerInterface) {
            return $this->runtimeContainer->hasParameter($name);
        }

        return $this->builder->hasParameter($name);
    }

    public function setParameter(string $name, array|bool|string|int|float|\UnitEnum|null $value): void
    {
        $this->builder->setParameter($name, $value);
    }

    private function bootstrapBuilder(): void
    {
        $this->builder->setParameter('kernel.environment', $this->config->env());
        $this->builder->setParameter('kernel.debug', defined('WP_DEBUG') && WP_DEBUG);
        $this->registerSynthetic(self::CONTAINER_ID, self::class);
        $this->registerSynthetic(self::CONFIG_ID, SiteConfig::class);
        $this->registerSynthetic(self::CONTEXT_ID, WpContext::class);
        $this->builder->setAlias(self::class, self::CONTAINER_ID)->setPublic(true);
        $this->builder->setAlias(PsrContainerInterface::class, self::CONTAINER_ID)->setPublic(true);
        $this->builder->setAlias(SymfonyContainerInterface::class, self::CONTAINER_ID)->setPublic(true);
        $this->builder->setAlias(SiteConfig::class, self::CONFIG_ID)->setPublic(true);
        $this->builder->setAlias(WpContext::class, self::CONTEXT_ID)->setPublic(true);
        $this->hydrateBuilder();
    }

    private function registerSynthetic(string $id, string $class): void
    {
        if ($this->builder->hasDefinition($id)) {
            return;
        }

        $this->builder->setDefinition(
            $id,
            (new Definition($class))
                ->setSynthetic(true)
                ->setPublic(true),
        );
    }

    private function hydrate(PsrContainerInterface $container): void
    {
        if (!method_exists($container, 'set')) {
            return;
        }

        $this->setSyntheticService($container, self::CONTAINER_ID, $this);
        $this->setSyntheticService($container, self::CONFIG_ID, $this->config);
        $this->setSyntheticService($container, self::CONTEXT_ID, $this->context);

        if ($this->kernel instanceof KernelInterface) {
            $this->setSyntheticService($container, self::KERNEL_ID, $this->kernel);
        }

        if (!($this->app instanceof App)) {
            return;
        }

        $this->setSyntheticService($container, self::APP_ID, $this->app);
    }

    private function setSyntheticService(PsrContainerInterface $container, string $id, object $service): void
    {
        try {
            $container->set($id, $service);
        } catch (ServiceNotFoundException) {
        }
    }

    private function getFromContainer(
        PsrContainerInterface $container,
        string $id,
        int $invalidBehavior,
    ): ?object {

        if ($container instanceof SymfonyContainerInterface) {
            return $container->get($id, $invalidBehavior);
        }

        $service = $container->get($id);

        if ($service === null && $invalidBehavior !== self::EXCEPTION_ON_INVALID_REFERENCE) {
            return null;
        }

        if (is_object($service)) {
            return $service;
        }

        throw new \RuntimeException(
            sprintf('Service "%s" must be an object, %s returned.', $id, get_debug_type($service)),
        );
    }

    private function handleMissingService(string $id, int $invalidBehavior): ?object
    {
        if (
            in_array(
                $invalidBehavior,
                [
                    self::NULL_ON_INVALID_REFERENCE,
                    self::IGNORE_ON_INVALID_REFERENCE,
                    self::IGNORE_ON_UNINITIALIZED_REFERENCE,
                ],
                true,
            )
        ) {
            return null;
        }

        throw new ServiceNotFoundException($id);
    }
}
