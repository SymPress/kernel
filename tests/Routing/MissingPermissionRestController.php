<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Routing;

use SymPress\Kernel\Attribute\Route;

final class MissingPermissionRestController
{
    #[Route(
        '/unsafe',
        methods: ['GET'],
        format: 'json',
        options: ['rest_namespace' => 'kernel-unsafe/v1'],
    )]
    public function __invoke(): array
    {
        return [];
    }
}
