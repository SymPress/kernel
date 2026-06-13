<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Service;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

#[AsDecorator(decorates: DecoratedGreeting::class)]
final class DecoratedGreetingDecorator extends DecoratedGreeting
{
    public function __construct(
        #[AutowireDecorated]
        private readonly DecoratedGreeting $inner,
    ) {
    }

    public function value(): string
    {
        return $this->inner->value() . '+decorated';
    }
}
