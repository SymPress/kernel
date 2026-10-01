<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

use Psr\Container\ContainerInterface;

final readonly class DefinitionGraphConsumer
{
    public function __construct(public ContainerInterface $locator)
    {
    }
}
