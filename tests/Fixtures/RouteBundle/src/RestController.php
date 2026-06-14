<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\RouteBundle\Src;

use SymPress\Kernel\Attribute\Route;

#[Route('/tools', options: ['rest_namespace' => 'kernel-fixture/v1'])]
final class RestController
{
    /**
     * @return array{id: string, route: string}
     */
    #[Route(
        '/items/{id<\d+>}',
        name: 'items_show',
        methods: ['GET'],
        format: 'json',
        options: [
            'args'                => ['id' => ['required' => true]],
            'permission_callback' => 'canRead',
        ],
    )]
    public function show(FakeRestRequest $request, string $id): array
    {
        return [
            'id'    => $id,
            'route' => (string) $request->get_param('route'),
        ];
    }

    public function canRead(FakeRestRequest $request): bool
    {
        return $request->get_param('allowed') === true;
    }
}
