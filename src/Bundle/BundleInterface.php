<?php

declare(strict_types=1);

namespace SymPress\Kernel\Bundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\BundleInterface as SymfonyBundleInterface;

// Framework 8.1 still aliases HttpKernel\KernelInterface, whose getBundle() requires this subtype.
// Retain its narrower return contract while inheriting the new DI interface through it.
// @phpstan-ignore interface.extendsDeprecatedInterface
interface BundleInterface extends SymfonyBundleInterface
{
    public function id(): string;

    public function path(): string;

    public function configPath(): ?string;

    /** @return list<string> */
    public function configPaths(): array;

    public function translationPath(): ?string;

    public function build(ContainerBuilder $container): void;
}
