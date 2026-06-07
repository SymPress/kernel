<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Service;

use SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Contract\LazyProbeInterface;
use Symfony\Component\DependencyInjection\Attribute\Lazy;

#[Lazy]
class LazyProbe implements LazyProbeInterface
{
    private static int $instances = 0;
    private string $state;

    public function __construct()
    {
        self::$instances++;
        $this->state = 'lazy';
    }

    public static function reset(): void
    {
        self::$instances = 0;
    }

    public static function instances(): int
    {
        return self::$instances;
    }

    public function touch(): string
    {
        return $this->state;
    }
}
