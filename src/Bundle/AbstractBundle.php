<?php

declare(strict_types=1);

namespace SymPress\Kernel\Bundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;

abstract class AbstractBundle implements BundleInterface
{
    public function id(): string
    {
        return static::class;
    }

    public function path(): string
    {
        $reflection = new \ReflectionObject($this);

        return dirname((string) $reflection->getFileName(), 2);
    }

    public function configPath(): ?string
    {
        $path = sprintf('%s/config', $this->path());

        if (!is_dir($path)) {
            return null;
        }

        return $path;
    }

    public function build(ContainerBuilder $container): void
    {
    }
}
