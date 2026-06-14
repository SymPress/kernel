<?php

declare(strict_types=1);

namespace SymPress\Kernel\Routing;

use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class RouteLoader
{
    public const string TAG = 'kernel.route_controller';

    /**
     * @param list<array<string, mixed>> $routes
     * @param list<array<string, mixed>> $restRoutes
     */
    public function __construct(
        private readonly ContainerInterface $controllers,
        private array $routes = [],
        private array $restRoutes = [],
    ) {
    }

    private ?RouteCollection $routeCollection = null;

    public function dispatchFrontendRequest(): void
    {
        $response = $this->handle(Request::createFromGlobals());

        if (!$response instanceof Response) {
            return;
        }

        $response->send();
        exit;
    }

    public function handle(Request $request): ?Response
    {
        if ($this->routes === []) {
            return null;
        }

        $matcher = new UrlMatcher(
            $this->routeCollection(),
            (new RequestContext())->fromRequest($request),
        );

        try {
            $attributes = $matcher->matchRequest($request);
        } catch (ResourceNotFoundException) {
            return null;
        } catch (MethodNotAllowedException $exception) {
            return new Response(
                '',
                Response::HTTP_METHOD_NOT_ALLOWED,
                ['Allow' => implode(', ', $exception->getAllowedMethods())],
            );
        }

        $request->attributes->add($attributes);

        return $this->normalizeResponse(
            $this->invokeHttpController($attributes, $request),
        );
    }

    public function registerRestRoutes(): void
    {
        if ($this->restRoutes === [] || !function_exists('register_rest_route')) {
            return;
        }

        foreach ($this->restRoutes as $route) {
            $rest = $route['rest'];
            $args = [
                'methods'             => $route['methods'] === [] ? 'GET' : $route['methods'],
                'callback'            => $this->restCallback($route),
                'permission_callback' => $this->restPermissionCallback($route),
                'args'                => $rest['args'] ?? [],
            ];

            if (($rest['show_in_index'] ?? null) !== null) {
                $args['show_in_index'] = (bool) $rest['show_in_index'];
            }

            register_rest_route(
                $rest['namespace'],
                $this->restPath($route),
                $args,
                (bool) ($rest['override'] ?? false),
            );
        }
    }

    public function routeCollection(): RouteCollection
    {
        if ($this->routeCollection instanceof RouteCollection) {
            return $this->routeCollection;
        }

        $collection = new RouteCollection();

        foreach ($this->routes as $definition) {
            $collection->add(
                $definition['name'],
                $this->symfonyRoute($definition),
                (int) ($definition['priority'] ?? 0),
            );
        }

        $this->routeCollection = $collection;

        return $collection;
    }

    /** @return list<array<string, mixed>> */
    public function restRoutes(): array
    {
        return $this->restRoutes;
    }

    /** @param array<string, mixed> $attributes */
    private function invokeHttpController(array $attributes, Request $request): mixed
    {
        $serviceId = $attributes['_controller_service'] ?? null;
        $method = $attributes['_controller_method'] ?? null;

        if (!is_string($serviceId) || !is_string($method)) {
            throw new \RuntimeException('Matched route does not define a valid controller callback.');
        }

        $service = $this->controllers->get($serviceId);

        return $this->invokeController($service, $method, $request, $attributes);
    }

    /** @param array<string, mixed> $route */
    private function restCallback(array $route): \Closure
    {
        return function (mixed $request = null) use ($route): mixed {
            $service = $this->controllers->get($route['service']);

            return $this->invokeController($service, $route['method'], $request, $this->restRequestParameters($request));
        };
    }

    /** @param array<string, mixed> $route */
    private function restPermissionCallback(array $route): callable
    {
        return function (mixed $request = null) use ($route): mixed {
            $permissionCallback = $route['rest']['permission_callback'] ?? null;

            if ($permissionCallback === null) {
                if (($route['rest']['public'] ?? false) === true) {
                    return true;
                }

                throw new \RuntimeException(
                    sprintf('REST route "%s" must define a permission callback or be explicitly public.', $route['name']),
                );
            }

            if (is_callable($permissionCallback)) {
                return $permissionCallback($request);
            }

            if (is_string($permissionCallback)) {
                $service = $this->controllers->get($route['service']);

                if (method_exists($service, $permissionCallback)) {
                    return $this->invokeController(
                        $service,
                        $permissionCallback,
                        $request,
                        $this->restRequestParameters($request),
                    );
                }

                if (function_exists($permissionCallback)) {
                    return $permissionCallback($request);
                }
            }

            throw new \RuntimeException(
                sprintf('REST route "%s" defines an invalid permission callback.', $route['name']),
            );
        };
    }

    /** @param array<string, mixed> $attributes */
    private function invokeController(object $service, string $method, mixed $request, array $attributes): mixed
    {
        $callback = [$service, $method];

        if (!is_callable($callback)) {
            throw new \RuntimeException(
                sprintf('Route controller "%s::%s" is not callable.', get_debug_type($service), $method),
            );
        }

        $reflection = new \ReflectionMethod($service, $method);
        $arguments = [];

        foreach ($reflection->getParameters() as $parameter) {
            $arguments[] = $this->controllerArgument($parameter, $request, $attributes);
        }

        return $callback(...$arguments);
    }

    /** @param array<string, mixed> $attributes */
    private function controllerArgument(\ReflectionParameter $parameter, mixed $request, array $attributes): mixed
    {
        $type = $parameter->getType();

        if (is_object($request) && $this->parameterAcceptsObject($parameter, $request)) {
            return $request;
        }

        if ($request instanceof Request && $parameter->getName() === 'request') {
            return $request;
        }

        if (array_key_exists($parameter->getName(), $attributes)) {
            return $attributes[$parameter->getName()];
        }

        if (is_object($request) && method_exists($request, 'has_param') && $request->has_param($parameter->getName())) {
            return $request->get_param($parameter->getName());
        }

        if (is_object($request) && method_exists($request, 'get_param')) {
            $value = $request->get_param($parameter->getName());

            if ($value !== null) {
                return $value;
            }
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($type instanceof \ReflectionType && $type->allowsNull()) {
            return null;
        }

        throw new \RuntimeException(
            sprintf('Cannot resolve route controller argument "$%s".', $parameter->getName()),
        );
    }

    private function parameterAcceptsObject(\ReflectionParameter $parameter, object $value): bool
    {
        $type = $parameter->getType();

        if ($type instanceof \ReflectionNamedType) {
            return !$type->isBuiltin() && is_a($value, $type->getName());
        }

        if (!$type instanceof \ReflectionUnionType) {
            return false;
        }

        foreach ($type->getTypes() as $namedType) {
            if (!$namedType instanceof \ReflectionNamedType || $namedType->isBuiltin()) {
                continue;
            }

            if (is_a($value, $namedType->getName())) {
                return true;
            }
        }

        return false;
    }

    private function normalizeResponse(mixed $value): Response
    {
        if ($value instanceof Response) {
            return $value;
        }

        if ($value === null) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        if (is_string($value) || $value instanceof \Stringable) {
            return new Response((string) $value);
        }

        return new JsonResponse($value);
    }

    /** @param array<string, mixed> $definition */
    private function symfonyRoute(array $definition): Route
    {
        return new Route(
            $definition['path'],
            $definition['defaults'] ?? [],
            $definition['requirements'] ?? [],
            $definition['options'] ?? [],
            $definition['host'] ?? '',
            $definition['schemes'] ?? [],
            $definition['methods'] ?? [],
            $definition['condition'] ?? '',
        );
    }

    /** @param array<string, mixed> $route */
    private function restPath(array $route): string
    {
        $explicitPath = $route['rest']['path'] ?? null;

        if (is_string($explicitPath) && $explicitPath !== '') {
            return str_starts_with($explicitPath, '/') ? $explicitPath : '/' . $explicitPath;
        }

        $regex = $this->symfonyRoute($route)->compile()->getRegex();
        $delimiter = $regex[0] ?? '';
        $closingDelimiter = match ($delimiter) {
            '{' => '}',
            '(' => ')',
            '[' => ']',
            '<' => '>',
            default => $delimiter,
        };
        $end = $closingDelimiter === '' ? false : strrpos($regex, $closingDelimiter);

        if ($end === false || $end === 0) {
            return $route['path'];
        }

        $path = substr($regex, 1, $end - 1);
        $path = preg_replace('/^\^/', '', $path) ?? $path;
        $path = preg_replace('/\$$/', '', $path) ?? $path;
        $path = str_replace('\\/', '/', $path);

        return str_starts_with($path, '/') ? $path : '/' . $path;
    }

    /** @return array<string, mixed> */
    private function restRequestParameters(mixed $request): array
    {
        if (!is_object($request)) {
            return [];
        }

        if (method_exists($request, 'get_url_params')) {
            $parameters = $request->get_url_params();

            if (is_array($parameters)) {
                return $parameters;
            }
        }

        return [];
    }
}
