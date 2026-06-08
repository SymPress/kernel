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
- testable package boundaries

## Requirements

- PHP `^8.5`
- Composer
- WordPress
- Symfony DependencyInjection, Config, Console, Filesystem, and Yaml components

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

SymPress discovers installed `sympress/*` Composer packages that expose
`extra.kernel` metadata.

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

Add package services in `config/services.yaml`:

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true
        public: false

    SymPress\ProjectPlugin\:
        resource: '../src/'
        exclude:
            - '../src/ProjectPluginBundle.php'
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
3. `BundleDiscovery` finds active SymPress packages and creates a
   `BundleRegistry`.
4. `Container` wraps Symfony's `ContainerBuilder`, then delegates to the
   compiled runtime container after boot.

Configuration is loaded in this order:

1. kernel package defaults
2. discovered bundle `config/` directories
3. site root `config/` directory

Within each config directory, the kernel loads:

- `packages/*.{php,yaml,yml}`
- `packages/{environment}/*.{php,yaml,yml}`
- `services.{php,yaml,yml}`
- `services_{environment}.{php,yaml,yml}`
- `wordpress.{php,yaml,yml}`
- `wordpress_{environment}.{php,yaml,yml}`

The compiled container is cached under `var/cache/{environment}/kernel`.
In debug mode, source and config contents are fingerprinted more strictly. In
production, cache invalidation follows file mtimes, `composer.lock`, or the
optional `SYMPRESS_KERNEL_BUILD_ID` value.

## Extension Points

Use these points before adding work to plugin boot files:

- Override `AbstractKernel::build(ContainerBuilder $builder)` for site-wide
  compiler passes or global container customization.
- Override `AbstractBundle::build(ContainerBuilder $builder)` for package-level
  compiler passes.
- Add `config/services.php`, `config/services.yaml`, `config/wordpress.php`, or
  environment-specific variants for package configuration.
- Use the `kernel.hook` service tag or `#[AsHook]` attribute for WordPress
  actions and filters.
- Use Symfony's `#[AsCommand]` attribute to expose console commands through the
  kernel console integration.
- Depend on the synthetic services `SymPress\Kernel\Container`,
  `SymPress\Kernel\SiteConfig`, `SymPress\Kernel\WpContext`,
  `SymPress\Kernel\Kernel\KernelInterface`, and `SymPress\Kernel\App` when a
  service needs runtime context.

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
composer test
```

## License

This package is licensed under `GPL-2.0-or-later`.
