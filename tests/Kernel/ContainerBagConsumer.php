<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;

final readonly class ContainerBagConsumer
{
    public function __construct(
        private ContainerBagInterface $parameters,
    ) {
    }

    public function environment(): string
    {
        return (string) $this->parameters->get('kernel.environment');
    }
}
