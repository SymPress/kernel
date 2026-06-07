<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ParameterMessage
{
    public function __construct(
        #[Autowire(param: 'kernel_fixture.parameter_message')]
        private readonly string $value,
    ) {
    }

    public function value(): string
    {
        return $this->value;
    }
}
