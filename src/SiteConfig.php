<?php

declare(strict_types=1);

namespace SymPress\Kernel;

use SymPress\Kernel\Location\Locations;

interface SiteConfig extends \JsonSerializable
{
    public const string HOSTING_VIP = 'vip';
    public const string HOSTING_WPE = 'wpe';
    public const string HOSTING_SPACES = 'spaces';
    public const string HOSTING_OTHER = 'other';

    public function locations(): Locations;

    public function hosting(): string;

    public function hostingIs(string $hosting): bool;

    public function env(): string;

    public function envIs(string $env): bool;

    public function get(string $name, mixed $default = null): mixed;
}
