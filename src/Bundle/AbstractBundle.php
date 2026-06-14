<?php

declare(strict_types=1);

namespace SymPress\Kernel\Bundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Container as SymfonyContainer;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\ConfigurableExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Kernel\BundleExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

abstract class AbstractBundle implements BundleInterface, ConfigurableExtensionInterface
{
    protected string $name;
    protected string $path;
    protected ?ContainerInterface $container = null;
    protected ExtensionInterface|false|null $extension = null;
    protected string $extensionAlias = '';

    public function id(): string
    {
        return static::class;
    }

    public function path(): string
    {
        return $this->getPath();
    }

    public function getPath(): string
    {
        if (isset($this->path)) {
            return $this->path;
        }

        $pathMethod = new \ReflectionMethod($this, 'path');

        if ($pathMethod->getDeclaringClass()->getName() !== self::class) {
            $this->path = $this->path();

            return $this->path;
        }

        $reflection = new \ReflectionObject($this);
        $this->path = dirname((string) $reflection->getFileName(), 2);

        return $this->path;
    }

    final public function getName(): string
    {
        if (isset($this->name)) {
            return $this->name;
        }

        if (str_contains(static::class, "@anonymous\0")) {
            $this->name = 'AnonymousBundle' . substr(hash('sha256', static::class), 0, 12);

            return $this->name;
        }

        $position = strrpos(static::class, '\\');
        $this->name = $position === false ? static::class : substr(static::class, $position + 1);

        return $this->name;
    }

    public function getNamespace(): string
    {
        return (new \ReflectionObject($this))->getNamespaceName();
    }

    public function configPath(): ?string
    {
        return $this->configPaths()[0] ?? null;
    }

    /** @return list<string> */
    public function configPaths(): array
    {
        $paths = [];

        foreach (['Resources/config', 'config'] as $directory) {
            $path = sprintf('%s/%s', $this->path(), $directory);

            if (!is_dir($path)) {
                continue;
            }

            $paths[] = $path;
        }

        return array_values(array_unique($paths));
    }

    public function translationPath(): ?string
    {
        $path = sprintf('%s/Resources/translations', $this->path());

        if (!is_dir($path)) {
            return null;
        }

        return $path;
    }

    public function build(ContainerBuilder $container): void
    {
        $this->registerContainerExtension($container);
    }

    final public function registerContainerExtension(ContainerBuilder $container): void
    {
        $extension = $this->getContainerExtension();

        if (!$extension instanceof ExtensionInterface) {
            return;
        }

        if ($container->hasExtension($extension->getAlias())) {
            return;
        }

        $container->registerExtension($extension);
    }

    public function boot(): void
    {
    }

    public function shutdown(): void
    {
    }

    public function setContainer(?ContainerInterface $container): void
    {
        $this->container = $container;
    }

    public function configure(DefinitionConfigurator $definition): void
    {
    }

    public function prependExtension(
        ContainerConfigurator $configurator,
        ContainerBuilder $container,
    ): void {
    }

    public function loadExtension(
        array $config,
        ContainerConfigurator $configurator,
        ContainerBuilder $container,
    ): void {
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        if ($this->extension !== null) {
            return $this->extension === false ? null : $this->extension;
        }

        $extensionClass = $this->containerExtensionClass();

        if ($extensionClass !== null) {
            $extension = new $extensionClass();

            if (!$extension instanceof ExtensionInterface) {
                throw new \RuntimeException(
                    sprintf(
                        'Container extension "%s" must implement %s.',
                        $extensionClass,
                        ExtensionInterface::class,
                    ),
                );
            }

            $this->extension = $extension;

            return $this->extension;
        }

        if ($this->extensionAlias === '') {
            $this->extensionAlias = SymfonyContainer::underscore(
                preg_replace('/Bundle$/', '', $this->getName()) ?? $this->getName(),
            );
        }

        $this->extension = new BundleExtension($this, $this->extensionAlias);

        return $this->extension;
    }

    private function containerExtensionClass(): ?string
    {
        $reflection = new \ReflectionObject($this);
        $namespace = $reflection->getNamespaceName();
        $shortName = $reflection->getShortName();

        if ($namespace === '') {
            return null;
        }

        if (str_ends_with($shortName, 'Bundle')) {
            $shortName = substr($shortName, 0, -6);
        }

        $extensionClass = sprintf('%s\\DependencyInjection\\%sExtension', $namespace, $shortName);

        return class_exists($extensionClass) ? $extensionClass : null;
    }
}
