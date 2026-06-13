<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

use Symfony\Contracts\Service\ResetInterface;

final class ResettableKernelService implements ResetInterface
{
    public static bool $touched = false;
    public static bool $reset = false;

    public static function resetState(): void
    {
        self::$touched = false;
        self::$reset = false;
    }

    public function touch(): void
    {
        self::$touched = true;
    }

    public function reset(): void
    {
        self::$reset = true;
    }
}
