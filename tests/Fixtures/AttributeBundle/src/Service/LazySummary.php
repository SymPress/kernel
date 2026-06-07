<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Service;

use SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Contract\LazyProbeInterface;

final class LazySummary
{
    public function __construct(
        private readonly LazyProbeInterface $probe,
    ) {
    }

    public function instances(): int
    {
        return LazyProbe::instances();
    }

    public function touch(): string
    {
        return $this->probe->touch();
    }
}
