<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

final class ShutdownHolder
{
    public function __construct(public readonly object $service)
    {
    }
}
