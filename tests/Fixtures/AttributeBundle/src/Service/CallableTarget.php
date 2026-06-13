<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Service;

final class CallableTarget
{
    public function format(string $value): string
    {
        return sprintf('callable:%s', $value);
    }

    public function describe(): string
    {
        return 'method-of';
    }
}
