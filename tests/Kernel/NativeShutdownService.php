<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

use Symfony\Contracts\Service\ShutdownInterface;

final class NativeShutdownService implements ShutdownInterface
{
    /** @var list<bool> */
    public array $calls = [];

    public function reset(bool $shutdown = false): void
    {
        $this->calls[] = $shutdown;
    }
}
