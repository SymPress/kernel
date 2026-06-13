<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Service;

class DecoratedGreeting
{
    public function value(): string
    {
        return 'base';
    }
}
