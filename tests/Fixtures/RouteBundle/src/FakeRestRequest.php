<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\RouteBundle\Src;

final class FakeRestRequest
{
    /** @param array<string, mixed> $parameters */
    public function __construct(
        private readonly array $parameters,
    ) {
    }

    // phpcs:disable PSR1.Methods.CamelCapsMethodName
    public function has_param(string $name): bool
    {
        return array_key_exists($name, $this->parameters);
    }

    public function get_param(string $name): mixed
    {
        return $this->parameters[$name] ?? null;
    }

    /** @return array<string, mixed> */
    public function get_url_params(): array
    {
        return [
            'id' => (string) ($this->parameters['id'] ?? ''),
        ];
    }

    // phpcs:enable PSR1.Methods.CamelCapsMethodName
}
