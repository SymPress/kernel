<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Service;

use SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Contract\TaggedGreetingInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTagDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

#[AsTagDecorator('kernel_fixture.tagged_greeting')]
final class TaggedGreetingDecorator implements TaggedGreetingInterface
{
    public function __construct(
        #[AutowireDecorated]
        private readonly TaggedGreetingInterface $inner,
    ) {
    }

    public function greeting(): string
    {
        return $this->inner->greeting() . '+tag-decorated';
    }
}
