# SymPress Kernel

`sympress/kernel` is the foundation package for SymPress. It gives WordPress
projects one Symfony-powered application kernel, one shared dependency injection
container, and a predictable way for Composer packages, plugins, MU plugins, and
themes to contribute services.

WordPress stays the runtime. The kernel adds structure around bootstrapping,
configuration, hooks, console commands, package discovery, and compiled service
containers.

## Why This Exists

WordPress projects often grow through plugin boot files, global functions, and
runtime hook registration. That works well at small scale, but it becomes hard
to test and hard to reason about once several packages need to collaborate.

The kernel keeps the parts WordPress is good at:

- the normal plugin, MU plugin, and theme lifecycle
- WordPress hooks as the integration boundary
- Composer packages that can be adopted one at a time

Then it adds the Symfony patterns that pay off in larger codebases:

- constructor-injected services
- bundle-level configuration
- compiler passes and autoconfiguration
- declarative hooks
- cached runtime containers
- Symfony-compatible bundle lifecycle hooks
- resettable services and service subscribers
- testable package boundaries

## Requirements

- PHP `>=8.4.1`
- Composer
- WordPress
- Symfony DependencyInjection `^8.1`, Config, Console, Filesystem, Service
  Contracts, EventDispatcher, Clock, ExpressionLanguage, and Yaml components

## Installation

```bash
composer require sympress/kernel
```

Boot the kernel from an MU plugin or another early WordPress bootstrap file:

```php
<?php

declare(strict_types=1);

use SymPress\Kernel\App;
use SymPress\Kernel\Kernel\SiteKernel;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

App::bootKernel(new SiteKernel(dirname(__DIR__, 2)));
```

After the kernel has booted, services can be resolved from the shared
application container:

```php
$mailer = App::make(App\Mailer\TransactionalMailer::class);
```

## Minimal Bundle

SymPress discovers installed Composer packages that expose `extra.kernel`
metadata. Projects that want to narrow discovery can set
`extra.kernel.package_prefixes` in the root `composer.json`.

```json
{
    "name": "sympress/project-plugin",
    "type": "wordpress-plugin",
    "extra": {
        "kernel": {
            "bundle": "SymPress\\ProjectPlugin\\ProjectPluginBundle",
            "entry": "project-plugin/project-plugin.php"
        }
    }
}
```

The bundle class can stay small:

```php
<?php

declare(strict_types=1);

namespace SymPress\ProjectPlugin;

use SymPress\Kernel\Bundle\AbstractBundle;

final class ProjectPluginBundle extends AbstractBundle
{
}
```

Add package services in `Resources/config/services.yaml`:

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true
        public: false

    SymPress\ProjectPlugin\:
        resource: '../../src/'
        exclude:
            - '../../src/ProjectPluginBundle.php'
```

Register a WordPress hook declaratively:

```php
<?php

declare(strict_types=1);

namespace SymPress\ProjectPlugin\Admin;

use SymPress\Kernel\Attribute\AsHook;

final class AdminMenu
{
    #[AsHook('admin_menu')]
    public function register(): void
    {
        add_options_page('Project', 'Project', 'manage_options', 'project', [$this, 'render']);
    }

    public function render(): void
    {
        echo '<div class="wrap"><h1>Project</h1></div>';
    }
}
```

The same hook can be registered with a service tag when central configuration is
clearer:

```yaml
services:
    SymPress\ProjectPlugin\Admin\AdminMenu:
        tags:
            - { name: kernel.hook, hook: 'admin_menu', method: register }
```

## Architecture

The runtime model has four layers:

1. `App` owns the singleton application instance and coordinates booting.
2. `SiteKernel` extends `AbstractKernel` and describes the WordPress site.
3. `BundleDiscovery` finds active kernel packages and creates a
   `BundleRegistry`.
4. `Container` implements Symfony's container interface, wraps a
   `ContainerBuilder`, then delegates to the compiled runtime container after
   boot.

Configuration is loaded in this order:

1. kernel package defaults
2. discovered bundle `Resources/config/` directories
3. site root `config/` directory

Within each config directory, the kernel loads:

- `packages/*.{php,yaml,yml,ini}`
- `packages/{environment}/*.{php,yaml,yml,ini}`
- `services.{php,yaml,yml,ini}`
- `services_{environment}.{php,yaml,yml,ini}`
- `wordpress.{php,yaml,yml,ini}`
- `wordpress_{environment}.{php,yaml,yml,ini}`

Files are imported through Symfony's `DelegatingLoader` with PHP, YAML, INI,
glob, directory, and closure loaders. The kernel file locator also understands
Symfony-style bundle resources such as `@ProjectPlugin/Resources/config/foo.yaml`.

The compiled container is cached under `var/cache/{environment}/kernel`.
In debug mode, source and config contents are fingerprinted more strictly. In
production, cache invalidation follows file mtimes, `composer.lock`, or the
optional `SYMPRESS_KERNEL_BUILD_ID` value.

## Extension Points

Use these points before adding work to plugin boot files:

- Override `AbstractKernel::build(ContainerBuilder $builder)` for site-wide
  compiler passes or global container customization.
- Add `DependencyInjection/{BundleName}Extension` to expose classic
  Symfony-style bundle configuration. The kernel registers it automatically.
- Or keep configuration directly in the bundle with Symfony 8.1's
  `configure()`, `prependExtension()`, and `loadExtension()` methods. When no
  classic extension class exists, the kernel uses Symfony's `BundleExtension`.
- Override `AbstractBundle::build(ContainerBuilder $builder)` for package-level
  compiler passes. Call `parent::build($builder)` when you want to preserve the
  automatic extension registration from custom build logic.
- Add `Resources/config/services.php`, `Resources/config/services.yaml`,
  `Resources/config/wordpress.php`, `.ini` variants, or environment-specific
  variants for package configuration. Package-level `config/` is still loaded
  as a compatibility fallback.
- Use the `kernel.hook` service tag or `#[AsHook]` attribute for WordPress
  actions and filters.
- Use Symfony's `#[AsCommand]` attribute to expose console commands through the
  kernel console integration.
- Use Symfony DI attributes from the component directly, including
  `#[Autowire]`, `#[AutowireIterator]`, `#[AutowireLocator]`, `#[AsAlias]`,
  `#[AsDecorator]`, `#[AsTagDecorator]`, `#[AutowireDecorated]`, `#[AutowireCallable]`,
  `#[AutowireMethodOf]`, `#[AutowireServiceClosure]`, `#[AutowireInline]`,
  `#[Required]`, `#[Target]`, `#[When]`, `#[WhenNot]`, `#[Lazy]`,
  `#[AutoconfigureResourceTag]`, and `#[Exclude]`.
- Use Symfony's `#[RequiredBundle]` attribute when one bundle must be loaded
  before another. Missing optional requirements can use `ignoreOnInvalid: true`.
- Depend on the synthetic services `SymPress\Kernel\Container`,
  `SymPress\Kernel\SiteConfig`, `SymPress\Kernel\WpContext`,
  `SymPress\Kernel\Kernel\KernelInterface`, and `SymPress\Kernel\App` when a
  service needs runtime context.

## Core Services

The runtime container exposes Symfony-compatible core IDs and aliases:

- `kernel`
- `service_container`
- `parameter_bag`
- `event_dispatcher`
- `filesystem`
- `clock`
- `file_locator`
- `reverse_container`
- `config_cache_factory`
- `services_resetter`
- `container.env_var_processor`
- `container.expression_language`
- `kernel.container`
- `kernel.config`
- `kernel.context`
- `kernel.kernel`
- `kernel.app`

`Symfony\Contracts\Service\ResetInterface` services are autoconfigured with the
`kernel.reset` tag and wired into `services_resetter`.
When the optional Symfony components are installed, EventDispatcher listeners,
subscribers, `LoggerAwareInterface`, Clock aliases, and PSR/EventDispatcher
aliases are autoconfigured in the same style as Symfony's DI ServicesBundle.

## Console

Commands tagged with `console.command` or marked with `#[AsCommand]` are exposed
through the kernel console integration. The kernel also ships lightweight
container tooling:

```bash
wp console debug:container
wp console debug:container --parameters
wp console debug:container --env-vars
wp console debug:container --tag=kernel.hook --types
wp console debug:container app --types --show-arguments
wp console lint:container
wp console container:dump --format=yaml
```

## Documentation

More focused docs live in `docs/`:

- `docs/boot-and-bundles.md`
- `docs/services-and-autowiring.md`
- `docs/attributes.md`
- `docs/hooks.md`
- `docs/showcase-plugin.md`

## Development

```bash
composer install
composer tests
composer qa
```

## License

This package is licensed under `GPL-2.0-or-later`.
