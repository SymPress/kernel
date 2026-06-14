<?php

declare(strict_types=1);

namespace SymPress\Kernel\Attribute;

use Symfony\Component\Routing\Attribute\DeprecatedAlias;

#[\Attribute(\Attribute::IS_REPEATABLE | \Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class Route
{
    /** @var list<string> */
    public array $methods;

    /** @var list<string> */
    public array $envs;

    /** @var list<string> */
    public array $schemes;

    /** @var list<string|DeprecatedAlias> */
    public array $aliases = [];

    /**
     * @param string|array<string, string>|null                 $path
     * @param array<string, string|\Stringable>                 $requirements
     * @param array<string, mixed>                              $options
     * @param array<string, mixed>                              $defaults
     * @param string|array<string>                              $methods
     * @param string|array<string>                              $schemes
     * @param string|array<string>|null                         $env
     * @param string|DeprecatedAlias|array<string|DeprecatedAlias> $alias
     */
    public function __construct(
        public string|array|null $path = null,
        public ?string $name = null,
        public array $requirements = [],
        public array $options = [],
        public array $defaults = [],
        public ?string $host = null,
        array|string $methods = [],
        array|string $schemes = [],
        public ?string $condition = null,
        public ?int $priority = null,
        ?string $locale = null,
        ?string $format = null,
        ?bool $utf8 = null,
        ?bool $stateless = null,
        string|array|null $env = null,
        string|DeprecatedAlias|array $alias = [],
    ) {

        $this->methods = array_values((array) $methods);
        $this->schemes = array_values((array) $schemes);
        $this->envs = array_values((array) $env);
        $this->aliases = is_array($alias) ? array_values($alias) : [$alias];

        if ($locale !== null) {
            $this->defaults['_locale'] = $locale;
        }

        if ($format !== null) {
            $this->defaults['_format'] = $format;
        }

        if ($utf8 !== null) {
            $this->options['utf8'] = $utf8;
        }

        if ($stateless === null) {
            return;
        }

        $this->defaults['_stateless'] = $stateless;
    }
}
