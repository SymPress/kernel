<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\RouteBundle\Src;

use SymPress\Kernel\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[Route('/kernel', name: 'kernel_')]
final class FrontendController
{
    #[Route('/hello/{name<[a-z]+>?world}', name: 'hello', methods: ['GET'], priority: 20)]
    public function hello(string $name, Request $request): Response
    {
        return new Response(sprintf('%s:%s', $name, $request->getMethod()));
    }
}
