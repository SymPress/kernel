# Boot and Bundles

## MU-Bootstrap

The global kernel is booted through an MU plugin:

```php
<?php

declare(strict_types=1);

use SymPress\Kernel\App;
use SymPress\Kernel\Kernel\SiteKernel;

App::bootKernel(new SiteKernel(dirname(__DIR__, 2)));
```

After that, plugins and themes no longer boot **their own container**. They only provide bundle metadata, code, and `Resources/config/`.

## Bundle Discovery

A bundle is registered through `composer.json > extra.kernel`:

```json
{
  "type": "wordpress-plugin",
  "extra": {
    "kernel": {
      "bundle": "SymPress\\Project\\ProjectBundle",
      "entry": "project/sympress-project.php"
    }
  }
}
```

Composer discovery can be narrowed through the root `composer.json`:

```json
{
  "extra": {
    "kernel": {
      "package_prefixes": ["sympress/", "brianvarskonst/"]
    }
  }
}
```

The kernel caches the matching Composer package manifest below
`var/cache/<environment>/kernel`. Cache hits reuse the manifest instead of
walking the full Composer installation, and the manifest is rebuilt when root or
installed Composer metadata changes.

For migration-heavy projects, `config/bundles.php` is also supported:

```php
<?php

return [
    SymPress\Project\ProjectBundle::class => ['all' => true],
    SymPress\Project\DebugBundle::class => ['dev' => true],
];
```

Bundles may declare Symfony-style bundle dependencies with `#[RequiredBundle]`.
The kernel discovers these recursively and registers the required bundle before
the bundle that declares it:

```php
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;

#[RequiredBundle(ProjectInfrastructureBundle::class)]
final class ProjectBundle extends AbstractBundle
{
}
```

As a WordPress fallback, the legacy `symfony_register_bundles` filter can append
bundle instances or bundle class names.

Discovery order:

1. MU-Plugins
2. Plugins
3. Theme
4. Site root config as the final override layer

## Bundle Class

The bundle class intentionally stays small:

```php
<?php

declare(strict_types=1);

namespace SymPress\Project;

use SymPress\Kernel\Bundle\AbstractBundle;

final class ProjectBundle extends AbstractBundle
{
}
```

`AbstractBundle` implements Symfony's DependencyInjection bundle interface and
keeps the legacy SymPress helpers (`id()`, `path()`, `configPaths()`,
`translationPath()`). The kernel calls:

- `build()` while the `ContainerBuilder` is being prepared
- `setContainer()` and `boot()` after the runtime container is ready
- `shutdown()` when the kernel is shut down

Only override `build(ContainerBuilder $container)` when you need compiler passes
or container customization. The kernel automatically registers
`DependencyInjection\ProjectExtension` for a `ProjectBundle` when that class
exists. Calling `parent::build($container)` from custom build logic is safe and
preserves the same behavior when a bundle is tested outside the full kernel.

Symfony 8.1-style configurable bundles are also supported. If no classic
extension class exists, the kernel creates Symfony's `BundleExtension` for the
bundle and calls the bundle methods directly:

```php
<?php

declare(strict_types=1);

namespace SymPress\Project;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use SymPress\Kernel\Bundle\AbstractBundle;

final class ProjectBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('api_key')->defaultValue('')->end()
            ->end();
    }

    public function prependExtension(
        ContainerConfigurator $configurator,
        ContainerBuilder $container,
    ): void {
        // Optional: prepend config before other extensions are loaded.
    }

    public function loadExtension(
        array $config,
        ContainerConfigurator $configurator,
        ContainerBuilder $container,
    ): void {
        $configurator->import($this->getPath() . '/Resources/config/services.yaml');
        $container->setParameter('project.api_key', $config['api_key']);
    }
}
```

Bundle resources can be located with Symfony-style names:

```php
$path = $kernel->locateResource('@ProjectBundle/Resources/config/services.yaml');
```

The same locator is used by the container loader, so config imports may also
reference `@ProjectBundle/...` resources.
