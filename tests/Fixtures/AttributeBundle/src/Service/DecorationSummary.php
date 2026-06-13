<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Service;

final readonly class DecorationSummary
{
    public function __construct(
        private DecoratedGreeting $greeting,
    ) {
    }

    public function value(): string
    {
        return $this->greeting->value();
    }
}
