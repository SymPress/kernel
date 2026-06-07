<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Contract;

interface LazyProbeInterface
{
    public function touch(): string;
}
