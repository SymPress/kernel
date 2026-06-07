<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\HookBundle\Src;

use SymPress\Kernel\Attribute\AsHook;

#[AsHook('plugins_loaded', priority: 9)]
final class HookedService
{
    public function __invoke(): void
    {
    }

    #[AsHook('admin_init', priority: 20)]
    public function register(string $context, int $priority): void
    {
    }
}
