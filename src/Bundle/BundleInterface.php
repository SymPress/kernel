<?php

declare(strict_types=1);

namespace SymPress\Kernel\Bundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;

interface BundleInterface
{
    public function id(): string;

    public function path(): string;

    public function configPath(): ?string;

    public function build(ContainerBuilder $container): void;
}
