<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Service;

use SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Contract\TaggedGreetingInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(tags: ['kernel_fixture.tagged_greeting'])]
final class TaggedGreeting implements TaggedGreetingInterface
{
    public function greeting(): string
    {
        return 'tagged';
    }
}
