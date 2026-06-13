<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Service;

use SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Contract\TaggedGreetingInterface;

final class TaggedGreetingSummary
{
    public function __construct(
        private readonly TaggedGreetingInterface $greeting,
    ) {
    }

    public function value(): string
    {
        return $this->greeting->greeting();
    }
}
