<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Routing;

use SymPress\Kernel\Attribute\Route;

final class PublicRestController
{
    #[Route(
        '/public',
        methods: ['GET'],
        format: 'json',
        options: [
            'rest_namespace' => 'kernel-public/v1',
            'public'         => true,
        ],
    )]
    public function __invoke(): array
    {
        return [];
    }
}
