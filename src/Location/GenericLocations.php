<?php

declare(strict_types=1);

namespace SymPress\Kernel\Location;

use SymPress\Kernel\EnvConfig;

final class GenericLocations implements Locations
{
    use ResolverTrait;

    public static function createFromConfig(EnvConfig $config): Locations
    {
        return new self($config);
    }

    private function __construct(EnvConfig $config)
    {
        $this->injectResolver(new LocationResolver($config));
    }
}
