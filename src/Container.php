<?php

declare(strict_types=1);

namespace SymPress\Kernel;

use Psr\Container\ContainerInterface;
use SymPress\Kernel\Kernel\KernelInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

final class Container implements ContainerInterface
{
    public const string APP_ID = 'kernel.app';
    public const string CONFIG_ID = 'kernel.config';
    public const string CONTAINER_ID = 'kernel.container';
    public const string CONTEXT_ID = 'kernel.context';
    public const string KERNEL_ID = 'kernel.kernel';

    private readonly ContainerBuilder $builder;
    private ?ContainerInterface $runtimeContainer = null;
    private ?App $app = null;
    private ?KernelInterface $kernel = null;

    /** @var array<int, ContainerInterface> */
    private array $containers;

    public function __construct(
        ?SiteConfig $config = null,
        ?WpContext $context = null,
        ?ContainerBuilder $builder = null,
        ContainerInterface ...$containers,
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

    public function runtimeContainer(): ?ContainerInterface
    {
        return $this->runtimeContainer;
    }

    public function setApp(App $app): void
    {
        $this->app = $app;
        $this->registerSynthetic(self::APP_ID, App::class);
        $this->builder->setAlias(App::class, self::APP_ID)->setPublic(true);
        $this->hydrateBuilder();

        if (!$this->runtimeContainer instanceof ContainerInterface) {
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

        if (!$this->runtimeContainer instanceof ContainerInterface) {
            return;
        }

        $this->hydrate($this->runtimeContainer);
    }

    public function addContainer(ContainerInterface $container): self
    {
        $this->containers[] = $container;

        return $this;
    }

    public function useRuntimeContainer(ContainerInterface $runtimeContainer): void
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

    public function get(mixed $id): mixed
    {
        $this->assertString($id, __METHOD__);

        if ($this->runtimeContainer instanceof ContainerInterface) {
            if ($this->runtimeContainer->has($id)) {
                return $this->runtimeContainer->get($id);
            }

            foreach ($this->containers as $container) {
                if ($container->has($id)) {
                    return $container->get($id);
                }
            }

            throw new ServiceNotFoundException($id);
        }

        if ($this->builder->has($id)) {
            return $this->builder->get($id);
        }

        foreach ($this->containers as $container) {
            if ($container->has($id)) {
                return $container->get($id);
            }
        }

        throw new ServiceNotFoundException($id);
    }

    public function has(mixed $id): bool
    {
        $this->assertString($id, __METHOD__);

        if ($this->runtimeContainer instanceof ContainerInterface) {
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

    private function bootstrapBuilder(): void
    {
        $this->builder->setParameter('kernel.environment', $this->config->env());
        $this->builder->setParameter('kernel.debug', defined('WP_DEBUG') && WP_DEBUG);
        $this->registerSynthetic(self::CONTAINER_ID, self::class);
        $this->registerSynthetic(self::CONFIG_ID, SiteConfig::class);
        $this->registerSynthetic(self::CONTEXT_ID, WpContext::class);
        $this->builder->setAlias(self::class, self::CONTAINER_ID)->setPublic(true);
        $this->builder->setAlias(ContainerInterface::class, self::CONTAINER_ID)->setPublic(true);
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

    private function hydrate(ContainerInterface $container): void
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

    private function setSyntheticService(ContainerInterface $container, string $id, mixed $service): void
    {
        try {
            $container->set($id, $service);
        } catch (ServiceNotFoundException) {
        }
    }

    private function assertString(mixed $value, string $method): void
    {
        if (is_string($value)) {
            return;
        }

        throw new \TypeError(
            sprintf(
                'Argument 1 passed to %s() must be a string, %s given.',
                $method,
                gettype($value),
            ),
        );
    }
}
