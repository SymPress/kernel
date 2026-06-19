<?php

declare(strict_types=1);

namespace SymPress\Kernel;

use SymPress\Kernel\Location\GenericLocations;
use SymPress\Kernel\Location\Locations;
use SymPress\Kernel\Location\VipLocations;
use SymPress\Kernel\Location\WpEngineLocations;

class EnvConfig implements SiteConfig
{
    public const string FILTER_ENV_NAME = 'kernel.environment';
    public const string LOCAL = 'local';
    public const string DEVELOPMENT = 'development';
    public const string PRODUCTION = 'production';
    public const string STAGING = 'staging';

    private const array ENV_ALIASES = [
        'local'          => self::LOCAL,
        'development'    => self::DEVELOPMENT,
        'dev'            => self::DEVELOPMENT,
        'develop'        => self::DEVELOPMENT,
        'staging'        => self::STAGING,
        'stage'          => self::STAGING,
        'preprod'        => self::STAGING,
        'pre-prod'       => self::STAGING,
        'pre-production' => self::STAGING,
        'test'           => self::STAGING,
        'uat'            => self::STAGING,
        'production'     => self::PRODUCTION,
        'prod'           => self::PRODUCTION,
        'live'           => self::PRODUCTION,
    ];

    private const array HOSTING_LOCATIONS_CLASS_MAP = [
        self::HOSTING_WPE    => WpEngineLocations::class,
        self::HOSTING_VIP    => VipLocations::class,
        self::HOSTING_SPACES => GenericLocations::class,
        self::HOSTING_OTHER  => GenericLocations::class,
    ];

    private string $env = '';

    /** @var array<string, mixed> */
    private array $data = [];

    /** @var array<int, string> */
    private array $namespaces = [];

    private ?Locations $locations = null;
    private string $hosting = '';

    public function __construct(string ...$namespaces)
    {
        foreach ($namespaces as $namespace) {
            $trimmed = trim($namespace, '\\');

            if ($trimmed === '') {
                continue;
            }

            $this->namespaces[] = $trimmed;
        }
    }

    public function locations(): Locations
    {
        if ($this->locations instanceof Locations) {
            return $this->locations;
        }

        $locationClassName = self::HOSTING_LOCATIONS_CLASS_MAP[$this->hosting()] ?? '';

        if (
            $locationClassName === ''
            || !class_exists($locationClassName)
        ) {
            $locationClassName = GenericLocations::class;
        }

        $factory = [$locationClassName, 'createFromConfig'];
        $locations = $factory($this);

        $this->locations = $locations;

        return $this->locations;
    }

    public function hosting(): string
    {
        if ($this->hosting !== '') {
            return $this->hosting;
        }

        $hosting = $this->get('HOSTING');

        if (is_string($hosting) && $hosting !== '') {
            $this->hosting = $hosting;

            return $this->hosting;
        }

        if ($this->get('VIP_GO_ENV') !== null) {
            $this->hosting = self::HOSTING_VIP;

            return $this->hosting;
        }

        if (function_exists('is_wpe')) {
            $this->hosting = self::HOSTING_WPE;

            return $this->hosting;
        }

        if ($this->get('SPACES_SPACE_ID')) {
            $this->hosting = self::HOSTING_SPACES;

            return $this->hosting;
        }

        $this->hosting = self::HOSTING_OTHER;

        return $this->hosting;
    }

    public function hostingIs(string $hosting): bool
    {
        return strtolower($this->hosting()) === strtolower($hosting);
    }

    public function env(): string
    {
        if ($this->env !== '') {
            return $this->env;
        }

        $env = function_exists('wp_get_environment_type') ? wp_get_environment_type() : null;
        $env = $env
            ?? $this->readEnvVarOrConstant('WP_ENVIRONMENT_TYPE')
            ?? $this->readEnvVarOrConstant('WP_ENV')
            ?? $this->readEnvVarOrConstant('WORDPRESS_ENV')
            ?? $this->readEnvVarOrConstant('VIP_GO_APP_ENVIRONMENT')
            ?? $this->readEnvVarOrConstant('VIP_GO_ENV');

        if (is_scalar($env) || $env instanceof \Stringable) {
            return $this->normalizeEnv((string) $env, false);
        }

        if (function_exists('is_wpe')) {
            $isWpe = is_wpe();
            $env = (is_bool($isWpe) && $isWpe)
                || (is_numeric($isWpe) && (int) $isWpe > 0)
                    ? self::PRODUCTION
                    : self::STAGING;
            $this->env = $this->normalizeEnv($env, true);

            return $this->env;
        }

        $env = defined('WP_DEBUG') && WP_DEBUG ? self::DEVELOPMENT : self::PRODUCTION;
        $this->env = $this->normalizeEnv($env, true);

        return $this->env;
    }

    public function envIs(string $env): bool
    {
        $lower = strtolower($env);
        $normalized = self::ENV_ALIASES[$lower] ?? $lower;

        return $this->env() === $normalized;
    }

    public function get(string $name, mixed $default = null): mixed
    {
        if ($name === '') {
            return $default;
        }

        if (array_key_exists($name, $this->data)) {
            return $this->data[$name];
        }

        foreach ($this->namespaces as $namespace) {
            $constant = sprintf('\\%s\\%s', $namespace, $name);

            if (defined($constant)) {
                $this->data[$name] = constant($constant);

                return $this->data[$name];
            }
        }

        $env = $this->readEnvVarOrConstant($name);

        if ($env !== null) {
            $this->data[$name] = $env;

            return $env;
        }

        $this->data[$name] = $default;

        return $default;
    }

    /** @return array{env: string, hosting: string, keys: list<string>} */
    public function jsonSerialize(): array
    {
        return [
            'env'     => $this->env(),
            'hosting' => $this->hosting(),
            'keys'    => array_keys($this->data),
        ];
    }

    private function readEnvVarOrConstant(string $name): mixed
    {
        if (defined($name)) {
            return constant($name);
        }

        $value = $_ENV[$name] ?? null;

        if ($value === null && stripos($name, 'HTTP_') !== 0) {
            $value = $_SERVER[$name] ?? null;
        }

        if ($value === null && in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
            $value = getenv($name) ?: null;
        }

        return $value;
    }

    private function normalizeEnv(string $env, bool $applyFilters): string
    {
        $lower = strtolower($env);
        $default = $applyFilters ? $lower : self::PRODUCTION;
        $normalized = self::ENV_ALIASES[$lower] ?? $default;

        if (!$applyFilters) {
            return $normalized;
        }

        $filtered = function_exists('apply_filters')
            ? apply_filters(self::FILTER_ENV_NAME, $normalized)
            : $normalized;

        if (is_string($filtered) && $filtered !== '') {
            $normalized = strtolower($filtered);
        }

        return self::ENV_ALIASES[$normalized] ?? self::PRODUCTION;
    }
}
