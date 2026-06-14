<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Routing;

use SymPress\Kernel\Attribute\Route;

final class ExplicitRestPathController
{
    #[Route(
        '/items/{slug}',
        methods: ['GET'],
        format: 'json',
        options: [
            'rest_namespace' => 'kernel-explicit/v1',
            'rest_path'      => '/stable/items/(?P<slug>[a-z0-9-]+)',
            'public'         => true,
        ],
    )]
    public function __invoke(): array
    {
        return [];
    }
}
