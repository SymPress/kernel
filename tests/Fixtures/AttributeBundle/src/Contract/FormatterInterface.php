<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Contract;

interface FormatterInterface
{
    public function format(string $value): string;
}
