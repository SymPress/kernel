# Routing

The kernel provides controller routing through one attribute:
`SymPress\Kernel\Attribute\Route`. The attribute mirrors Symfony's route
attribute parameters and is applied to services discovered by the container.

There is no separate REST attribute. A route becomes a WordPress REST endpoint
when it declares `format: 'json'`. Routes without `format: 'json'` are frontend
routes and are matched during `template_redirect`.

## Frontend Routes

Frontend routes are useful for page-like controllers, rendered views, redirects,
or other HTTP responses that should not be registered in the WordPress REST API.

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use SymPress\Kernel\Attribute\Route;
use Symfony\Component\HttpFoundation\Response;

#[Route('/account', name: 'account_')]
final class AccountController
{
    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        return new Response('<h1>Dashboard</h1>');
    }
}
```

Supported controller return values for frontend routes:

- `Symfony\Component\HttpFoundation\Response`
- `string` or `Stringable`
- `null`, returned as `204 No Content`
- arrays or other values, returned as a `JsonResponse`

Route placeholders, defaults, requirements, methods, schemes, hosts, priority,
locale, UTF-8, stateless routes, and conditions follow Symfony Routing behavior.

```php
#[Route('/posts/{id<\d+>}', name: 'post_show', methods: ['GET'])]
public function show(string $id): Response
{
    return new Response('Post ' . $id);
}
```

Controller arguments are resolved from the matched route attributes by argument
name. A `Symfony\Component\HttpFoundation\Request` argument receives the current
request.

## REST Routes

Declare `format: 'json'` to register the route with `register_rest_route()` on
WordPress' `rest_api_init` hook.

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use SymPress\Kernel\Attribute\Route;
use WP_REST_Request;

#[Route('/tools', options: ['rest_namespace' => 'app/v1'])]
final class ToolApiController
{
    #[Route(
        '/items/{id<\d+>}',
        name: 'tool_item_show',
        methods: ['GET'],
        format: 'json',
        options: [
            'args' => [
                'id' => [
                    'required' => true,
                ],
            ],
            'permission_callback' => 'canRead',
        ],
    )]
    public function show(WP_REST_Request $request, string $id): array
    {
        return [
            'id' => $id,
            'source' => $request->get_param('source'),
        ];
    }

    public function canRead(WP_REST_Request $request): bool
    {
        return current_user_can('read');
    }
}
```

REST route requirements:

- `format: 'json'` marks the route as a REST endpoint.
- `options['rest_namespace']` is required. `options['namespace']` is accepted as
  an alias.
- `methods` is passed to WordPress. If omitted for a REST route, the kernel uses
  `GET`.

REST-specific options:

- `args`: passed to WordPress as the route argument schema.
- `permission_callback`: a callable, global function name, or controller method
  name.
- `rest_path`: explicit WordPress REST path. Use this for complex Symfony route
  requirements where the generated WordPress route should be reviewed directly.
- `public`: set to `true` only for intentionally public endpoints. REST routes
  without `permission_callback` and without `public: true` fail during container
  compilation.
- `show_in_index`: passed to WordPress when set to a boolean.
- `override`: passed as the fourth `register_rest_route()` argument.

Class-level `Route` options are inherited by method routes. This makes the REST
namespace a good fit for the controller class:

```php
#[Route('/billing', options: ['rest_namespace' => 'app/v1'])]
final class BillingApiController
{
    #[Route('/invoices', methods: ['GET'], format: 'json')]
    public function invoices(): array
    {
        return [];
    }
}
```

For routes with complex WordPress regex requirements, declare the REST path
explicitly:

```php
#[Route(
    '/items/{slug}',
    methods: ['GET'],
    format: 'json',
    options: [
        'rest_namespace' => 'app/v1',
        'rest_path' => '/items/(?P<slug>[a-z0-9-]+)',
        'public' => true,
    ],
)]
public function item(string $slug): array
{
    return ['slug' => $slug];
}
```

If `format: 'json'` is placed on the class-level route, all child routes inherit
that format and are treated as REST routes unless a method overrides the format.

## Service Configuration

Route attributes are applied through autoconfiguration. Make sure controller
classes are loaded as services and resource-scanned:

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true
        public: false

    App\Controller\:
        resource: '../../src/Controller/'
```

The controller service itself stays lazy. The kernel registers a service locator
for matched controllers and only resolves a controller when a matching frontend
request or REST endpoint callback is executed.

## Symfony Compatibility

The kernel route attribute accepts the same main parameters as Symfony's
`#[Route]`: `path`, `name`, `requirements`, `options`, `defaults`, `host`,
`methods`, `schemes`, `condition`, `priority`, `locale`, `format`, `utf8`,
`stateless`, `env`, and `alias`.

The Symfony route attribute class is also recognized when it is installed:

```php
use Symfony\Component\Routing\Attribute\Route;
```

For WordPress REST routes, prefer the kernel attribute or ensure the Symfony
attribute includes `format: 'json'` and the required `options['rest_namespace']`.
