<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Service;

final readonly class InlineDependency
{
    public function __construct(
        private string $value = 'service',
    ) {
    }

    public function value(): string
    {
        return $this->value;
    }
}
