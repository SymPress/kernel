<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

final class DefinitionCloneProduct
{
    public string $configured = '';

    public function __construct(public string $label)
    {
    }

    public static function create(string $label): self
    {
        return new self('factory/' . $label);
    }

    public static function createChanged(string $label): self
    {
        return new self('changed/' . $label);
    }

    public static function configure(self $product): void
    {
        $product->configured = 'parent';
    }

    public static function configureChanged(self $product): void
    {
        $product->configured = 'changed';
    }
}
