<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

use RuntimeException;

final class ShutdownResource
{
    public int $closed = 0;
    public ?\Closure $onClose = null;

    public function __construct(private readonly bool $fails = false)
    {
    }

    public function close(): void
    {
        ++$this->closed;
        ($this->onClose ?? static function (): void {
        })();
        if ($this->fails) {
            throw new RuntimeException('service failure');
        }
    }
}
