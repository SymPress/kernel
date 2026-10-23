<?php

declare(strict_types=1);

namespace SymPress\Kernel\Kernel;

use Symfony\Contracts\Service\ShutdownInterface;

/** @internal Capability bridge while Symfony 8.1 and 8.2 are both supported. */
final class NativeServiceShutdown
{
    public static function supports(object|string|null $resetter): bool
    {
        return $resetter !== null && interface_exists(ShutdownInterface::class) && is_a($resetter, ShutdownInterface::class, true);
    }

    public static function reset(object $resetter): void
    {
        if (!self::supports($resetter) || !method_exists($resetter, 'reset')) {
            return;
        }

        $resetter->reset(true);
    }
}
