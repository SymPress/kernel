# Dependency Injection Kernel

This document describes `sympress/kernel` as a WordPress kernel built on
`symfony/dependency-injection`. It follows the Symfony component model and maps
the service-container topics from Symfony's documentation to the concrete kernel
implementation in this repository.

## Installation

The kernel is a Composer package. It depends on the Symfony components used to
build, configure, dump, and run the container:

```json
{
  "require": {
    "symfony/config": "^8.0",
    "symfony/console": "^8.0",
    "symfony/clock": "^8.0",
    "symfony/dependency-injection": "^8.1",
    "symfony/event-dispatcher": "^8.0",
    "symfony/expression-language": "^8.0",
    "symfony/filesystem": "^8.0",
    "symfony/service-contracts": "^3.6",
    "symfony/yaml": "^8.0"
  }
}
```

In a WordPress project, `sympress/kernel` is installed through Composer and
booted from an MU plugin or another early bootstrap file. The kernel builds one
global site container for active MU plugins, plugins, themes, libraries, and
site-level configuration.

```php
<?php

declare(strict_types=1);

use SymPress\Kernel\App;
use SymPress\Kernel\Kernel\SiteKernel;

App::bootKernel(new SiteKernel(dirname(__DIR__, 2)));
```

## Core Idea

Symfony describes the dependency injection container as the central place where
object construction is standardized. The kernel brings that same idea to
WordPress:

- Services are defined in the container instead of plugin bootstrap files.
- Plugins and themes contribute bundles with `Resources/config/` or `config/`.
- Runtime code uses a compiled Symfony container instead of scattered global
  singletons.
- WordPress hooks are declared as service tags or attributes and registered
  during boot.

The kernel is not a copy of Symfony FrameworkBundle. It uses the original
DependencyInjection API, especially `ContainerBuilder`, `Definition`,
`Reference`, `DelegatingLoader`, `PhpFileLoader`, `YamlFileLoader`,
`IniFileLoader`, `GlobFileLoader`, `DirectoryLoader`, `ClosureLoader`,
`BundleExtension`, `PhpDumper`, compiler passes, attribute autoconfiguration,
`ServiceLocatorTagPass`, `ResettableServicePass`, and
`MergeExtensionConfigurationPass`. On top of that, it adds a WordPress-specific
bundle, hook, route, and runtime layer.

## Boot Workflow

The main entry point is `SymPress\Kernel\App::boot()`.

1. `App` creates the kernel and the initial wrapper container.
2. The kernel discovers active bundles from Composer metadata,
   `config/bundles.php`, and the legacy `symfony_register_bundles` filter.
3. If a matching runtime container exists in the cache, that container is used.
4. Otherwise the kernel prepares a `ContainerBuilder`, registers bundle
   extensions, loads configuration, compiles the builder, and dumps it with
   `PhpDumper` into `var/cache/<env>/kernel`.
5. The runtime container is hydrated with synthetic runtime instances: the
   kernel, app, site config, WordPress context, and wrapper container.
6. `HookLoader` registers compiled `kernel.hook` entries with WordPress.
7. Bundles and the kernel are considered booted.

During this workflow the kernel emits these WordPress actions:

- `kernel.booting`
- `kernel.before_container_build`
- `kernel.container_configured`
- `kernel.container_ready`
- `kernel.booted`
- `kernel.error`

Legacy compatibility actions are still emitted:

- `symfony_before_container_build`
- `symfony_container_ready`
- `symfony_container_loaded`

## Bundle Discovery

A bundle is described in `composer.json > extra.kernel`:

The accepted keys and required bundle/root shapes are defined by the
[`extra.kernel` JSON Schema](../schema/kernel-extra.schema.json).

```json
{
  "type": "wordpress-plugin",
  "extra": {
    "kernel": {
      "bundle": "Acme\\Demo\\DemoBundle",
      "entry": "demo/demo.php"
    }
  }
}
```

Dependent bundles can declare `requires`. A package is loaded as a bundle only
when its requirements are installed and active.

```json
{
  "extra": {
    "kernel": {
      "bundle": "Acme\\MailerPro\\MailerProBundle",
      "entry": "mailer-pro/mailer-pro.php",
      "requires": ["acme/mailer"]
    }
  }
}
```

Projects can restrict discovery to package prefixes in the root
`composer.json`:

```json
{
  "extra": {
    "kernel": {
      "package_prefixes": ["sympress/", "brianvarskonst/"]
    }
  }
}
```

Composer package discovery writes a small manifest cache below
`var/cache/<environment>/kernel`. The manifest contains only packages that match
the configured prefixes and declare `extra.kernel`, so runtime cache hits do not
need to scan every installed Composer package. The manifest is invalidated when
root Composer metadata or Composer's installed package metadata changes.

Manual bundles can also be registered:

```php
<?php

return [
    Acme\Demo\DemoBundle::class => ['all' => true],
    Acme\Demo\DebugBundle::class => ['development' => true],
];
```

Discovery order:

1. Composer bundles from active MU plugins, plugins, themes, and libraries.
2. Manual bundles from `config/bundles.php`.
3. Legacy bundles from `symfony_register_bundles`.
4. Sorting by type: MU plugin, plugin, theme, library.

## Bundle Classes

A bundle class should stay small:

```php
<?php

declare(strict_types=1);

namespace Acme\Demo;

use SymPress\Kernel\Bundle\AbstractBundle;

final class DemoBundle extends AbstractBundle
{
}
```

`AbstractBundle` automatically looks for:

- `Resources/config/`
- `config/`
- `Resources/translations/`
- `DependencyInjection\<BundleName>Extension`

Override `build(ContainerBuilder $container)` only when a bundle needs compiler
passes or very specific container customization. If `build()` is overridden,
call `parent::build($container)` when you still want automatic extension
registration.

A bundle can also implement Symfony 8.1 bundle-extension methods directly. When
no classic `DependencyInjection\<BundleName>Extension` exists, the kernel
creates a `BundleExtension` and calls `configure()`, `prependExtension()`, and
`loadExtension()` on the bundle.

```php
<?php

declare(strict_types=1);

namespace Acme\Demo;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use SymPress\Kernel\Bundle\AbstractBundle;

final class DemoBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('api_key')->defaultValue('')->end()
            ->end();
    }

    public function loadExtension(
        array $config,
        ContainerConfigurator $configurator,
        ContainerBuilder $container,
    ): void {
        $configurator->import($this->getPath() . '/Resources/config/services.yaml');
        $container->setParameter('demo.api_key', $config['api_key']);
    }
}
```

Bundles also receive the Symfony lifecycle. `setContainer()`, `boot()`, and
`shutdown()` are called around the runtime container.

## Container Configuration

The kernel loads configuration in this order:

1. Kernel defaults from `packages/kernel/config`
2. Bundle configuration from `Resources/config/` and `config/`
3. Site configuration from `<project>/config`

Site configuration therefore overrides bundle defaults.

Each configuration directory can contain these patterns:

```text
packages/*.{php,yaml,yml,ini}
packages/<env>/*.{php,yaml,yml,ini}
services.{php,yaml,yml,ini}
services_<env>.{php,yaml,yml,ini}
wordpress.{php,yaml,yml,ini}
wordpress_<env>.{php,yaml,yml,ini}
```

Configuration is read through Symfony's `DelegatingLoader`:

- `PhpFileLoader`
- `YamlFileLoader`
- `IniFileLoader`
- `GlobFileLoader`
- `DirectoryLoader`
- `ClosureLoader`
- `SymPress\Kernel\Kernel\FileLocator`

The kernel `FileLocator` delegates normal paths to Symfony and resolves bundle
resources such as `@DemoBundle/Resources/config/services.yaml` through
`KernelInterface::locateResource()`.

A typical bundle service configuration lives in
`Resources/config/services.yaml`:

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true
        public: false

    Acme\Demo\:
        resource: '../../src/'
        exclude:
            - '../../src/DemoBundle.php'
```

`config/services.yaml` is still loaded as a compatibility fallback. From that
directory, the relative service resource is usually `../src/`.

The resource definition matters because PHP attributes are applied when Symfony
discovers and reflects the affected classes during a service resource scan.

## Parameters

The kernel defines these core parameters:

```text
kernel.project_dir
kernel.environment
kernel.debug
kernel.cache_dir
kernel.build_dir
kernel.share_dir
kernel.logs_dir
kernel.package_prefixes
kernel.package_manager.enabled
kernel.translation_paths
kernel.bundles
kernel.bundles_metadata
kernel.container_class
.kernel.config_dir
```

Only selected non-sensitive application environment variables are exposed as
parameters. The default allowlist covers operational values such as
`APP_ENV`, `APP_RUNTIME_ENV`, `APP_CACHE_DIR`, `APP_BUILD_DIR`,
`KERNEL_PACKAGE_PREFIXES`, `SYMPRESS_KERNEL_BUILD_ID`, and
`SYMPRESS_KERNEL_VALIDATE_SOURCE_RESOURCES`.

Typical request and PHP server values such as `HTTP_*`, `REQUEST_*`,
`SCRIPT_*`, `PHP_*`, and `DOCUMENT_*` are intentionally excluded. WordPress and
database values are not imported by wildcard; only explicit non-sensitive names
such as `WP_ENV`, `WP_ENVIRONMENT_TYPE`, and `WORDPRESS_ENV` are allowed because
container dumps/cache files can persist parameter values.

Secret-looking names are denied even when they would otherwise match an allowed
prefix. This includes names containing `AUTH`, `CERT`, `CREDENTIAL`, `KEY`,
`PASS`, `PASSWORD`, `PRIVATE`, `SALT`, `SECRET`, or `TOKEN`.

Sites can allow additional non-sensitive values through `KERNEL_ENV_PARAMETERS`
as a comma-separated list:

```bash
KERNEL_ENV_PARAMETERS=APP_PUBLIC_API_BASE_URL,APP_BUILD_CHANNEL
```

Example:

```yaml
services:
    Acme\Demo\Service\ApiClient:
        arguments:
            $baseUrl: '%env.app_public_api_base_url%'
```

Percent signs in imported string values are escaped so Symfony does not
accidentally interpret them as parameter placeholders.

## Services and Autowiring

The kernel follows Symfony's service container rules. Constructor injection is
the default, and explicit service definitions are reserved for the places where
the container needs extra information.

```php
final class NewsletterHandler
{
    public function __construct(
        private readonly NewsletterService $newsletter,
        private readonly LoggerInterface $logger,
    ) {
    }
}
```

With `_defaults.autowire: true`, Symfony reads constructor type declarations and
injects matching services automatically. The resource scan shown above is enough
for normal classes:

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    Acme\Demo\:
        resource: '../../src/'
        exclude:
            - '../../src/DemoBundle.php'
```

Autowiring is deterministic. If an argument is type-hinted with a concrete
class, Symfony uses the service whose ID is that class name. If the argument is
type-hinted with an interface, there must be exactly one matching alias or a
more specific named autowiring alias.

```php
namespace Acme\Demo\Report;

final class ReportController
{
    public function __construct(
        private readonly ReportBuilder $builder,
    ) {
    }
}
```

```yaml
services:
    Acme\Demo\Report\:
        resource: '../../src/Report/'
```

The `ReportController` service can be built because `ReportBuilder` is also
registered as a service in the scanned namespace.

### Interfaces and Aliases

When a constructor asks for an interface, point that interface at the default
implementation:

```php
namespace Acme\Demo\Contract;

interface FormatterInterface
{
    public function format(array $payload): string;
}
```

```php
namespace Acme\Demo\Formatter;

use Acme\Demo\Contract\FormatterInterface;

final class HtmlFormatter implements FormatterInterface
{
    public function format(array $payload): string
    {
        return '<pre>' . esc_html(wp_json_encode($payload)) . '</pre>';
    }
}
```

```yaml
services:
    Acme\Demo\Contract\FormatterInterface:
        alias: Acme\Demo\Formatter\HtmlFormatter
```

Services are private by default. Mark services public only when they are real
entry points that WordPress glue code, tests, CLI commands, or compatibility
layers intentionally fetch from the container.

```yaml
services:
    Acme\Demo\Admin\SettingsPage:
        public: true
```

### Multiple Implementations and `#[Target]`

If several services implement the same interface, use named autowiring aliases.
This mirrors Symfony's autowiring behavior and keeps the choice local to the
argument through the `#[Target]` attribute.

```yaml
services:
    'Acme\Demo\Contract\FormatterInterface $adminFormatter':
        alias: Acme\Demo\Formatter\HtmlFormatter

    'Acme\Demo\Contract\FormatterInterface $apiFormatter':
        alias: Acme\Demo\Formatter\JsonFormatter
```

```php
use Acme\Demo\Contract\FormatterInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

final class FormatterSelection
{
    public function __construct(
        #[Target('adminFormatter')]
        private readonly FormatterInterface $adminFormatter,
        #[Target('apiFormatter')]
        private readonly FormatterInterface $apiFormatter,
    ) {
    }
}
```

For Symfony 8.1 and newer, prefer `#[Target]` for named autowiring aliases.
Relying only on the parameter name is deprecated. The value passed to
`#[Target]` is the named alias target, not a service ID.

### Scalar Values and `#[Autowire]`

Autowiring cannot guess scalar values such as strings, integers, arrays, DSNs,
feature flags, or WordPress option names. Use explicit arguments, binds, or the
Symfony `#[Autowire]` attribute.

```yaml
parameters:
    demo.api_base_url: 'https://api.example.test'

services:
    Acme\Demo\Api\ApiClient:
        arguments:
            $baseUrl: '%demo.api_base_url%'
```

The same choice can be expressed at the constructor argument:

```php
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ApiClient
{
    public function __construct(
        #[Autowire(param: 'demo.api_base_url')]
        private readonly string $baseUrl,
    ) {
    }
}
```

Use YAML for shared package policy and attributes for values that are tightly
bound to the consuming class.

### Method and Setter Injection

Constructor injection remains preferred. Use method or setter injection for
optional dependencies or dependencies that must be configured after
construction. Symfony's `#[Required]` attribute marks those methods for
automatic calls during service creation.

```php
use Symfony\Contracts\Service\Attribute\Required;

final class RequiredSummary
{
    private ?HtmlFormatter $formatter = null;

    #[Required]
    public function setFormatter(HtmlFormatter $formatter): void
    {
        $this->formatter = $formatter;
    }
}
```

The equivalent YAML definition remains available:

```yaml
services:
    Acme\Demo\Service\RequiredSummary:
        calls:
            - setFormatter: ['@Acme\Demo\Formatter\HtmlFormatter']
```

### Tags and Tagged Collections

Tags model loosely coupled plugin extensions. Interfaces can declare their
default tag with `#[AutoconfigureTag]`, and implementations can refine the tag
metadata with `#[AsTaggedItem]`.

```php
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('demo.panel')]
interface PanelInterface
{
    public function title(): string;
}
```

```php
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'primary', priority: 20)]
final class PrimaryPanel implements PanelInterface
{
    public function title(): string
    {
        return 'Primary';
    }
}
```

Inject tagged services with `#[AutowireIterator]`:

```php
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class PanelSummary
{
    public function __construct(
        #[AutowireIterator('demo.panel', indexAttribute: 'index')]
        private readonly iterable $panels,
    ) {
    }
}
```

The YAML equivalent is:

```yaml
services:
    Acme\Demo\Panel\PrimaryPanel:
        tags:
            - { name: demo.panel, index: primary, priority: 20 }

    Acme\Demo\PanelRegistry:
        arguments:
            $panels: !tagged_iterator demo.panel
```

### Service Locators

Service locators are useful for lazy access to a small, known set of services.
They are the clean alternative to arrays of service IDs or injecting the whole
application container.

```php
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final class FormatterLocator
{
    public function __construct(
        #[AutowireLocator([
            'html' => HtmlFormatter::class,
            'json' => JsonFormatter::class,
        ])]
        private readonly ContainerInterface $formatters,
    ) {
    }
}
```

The kernel uses the same idea internally for WordPress hooks:
`HookCompilerPass` collects `kernel.hook` services and registers them through
`ServiceLocatorTagPass::register()`. Hook services are fetched from the
container only when WordPress executes the hook.

## Factories

Factories are a good fit for services whose construction depends on WordPress
state, legacy APIs, or external SDKs.

```yaml
services:
    Acme\Demo\Calendar\CalendarService:
        factory: ['Acme\Demo\Factory\CalendarFactory', 'create']
```

Static factories and service-method factories are also supported:

```yaml
services:
    Acme\Demo\EventSystem:
        factory: ['Acme\Demo\EventSystem', 'getInstance']
        public: true

    Acme\Demo\EventDispatcher:
        factory: ['@Acme\Demo\EventSystem', 'getDispatcher']
```

## Lazy Services and Service Closures

Expensive integrations should be lazy so normal WordPress requests do not
materialize unnecessary objects.

```php
use Symfony\Component\DependencyInjection\Attribute\Lazy;

#[Lazy]
final class ExpensiveApiClient
{
}
```

Service closures are available when exactly one service should be created only
on demand:

```yaml
services:
    Acme\Demo\Service\ReportBuilder:
        arguments:
            $clientFactory: !service_closure '@Acme\Demo\Api\ExpensiveApiClient'
```

The hook system already has lazy behavior built in because hook callbacks are
closures that fetch the real service from a locator when WordPress calls the
hook.

## Optional Dependencies

Optional services should be modeled explicitly:

```yaml
services:
    Acme\Demo\Service\OptionalIntegration:
        arguments:
            $logger: '@?logger'
```

Symfony can also ignore missing optional services in method calls:

```yaml
services:
    Acme\Demo\Service\OptionalIntegration:
        calls:
            - setProfiler: ['@?Acme\Demo\Profiler\Profiler']
```

For kernel code, a missing optional WordPress service should not break the
entire kernel boot. Required services should fail early during compilation.

## Non-Shared Services

Symfony services are shared by default. Use `shared: false` for stateful objects
that must be recreated for each access.

```yaml
services:
    Acme\Demo\RequestScoped\TemporaryBuffer:
        shared: false
```

Use this sparingly in WordPress. Many services already live for only one
request, and extra instances can make debugging and hook behavior harder to
reason about.

## Parent Services

Parent services can bundle common definition parts:

```yaml
services:
    Acme\Demo\AbstractWebhookHandler:
        abstract: true
        arguments:
            $logger: '@logger'

    Acme\Demo\StripeWebhookHandler:
        parent: Acme\Demo\AbstractWebhookHandler
        arguments:
            $topic: 'stripe'
```

The kernel fully compiles the container, so Symfony features such as parent
services are available whenever the selected loaders and definitions support
them.

## Service Decoration

Service decoration is useful when a bundle wants to extend another service's
behavior without replacing that service's class.

```yaml
services:
    Acme\Demo\TracingMailer:
        decorates: Acme\Mailer\MailerInterface
        arguments:
            $inner: '@Acme\Demo\TracingMailer.inner'
```

Prefer decoration over global WordPress filters when the change is truly about
service behavior. For WordPress output, hooks, and plugin integrations,
`kernel.hook` tags are often clearer.

## Configurators

Symfony configurators call a method or callable after construction to finish
setting up a service. This is useful when a service cannot reasonably accept
all options in its constructor.

```yaml
services:
    Acme\Demo\Service\ConfiguredClient:
        configurator: ['Acme\Demo\Factory\ClientConfigurator', 'configure']
```

Prefer constructor injection for new services. Configurators are mostly useful
for third-party objects and legacy objects.

## Expressions

Symfony expressions can compute special values from services, parameters, or
environment values.

```yaml
services:
    Acme\Demo\Service\ApiClient:
        arguments:
            $dsn: '@=service("Acme\\Demo\\Config\\ApiConfig").dsn()'
```

In kernel packages, expressions should be rare. Small factory services are
usually easier to test and debug.

## Core and Synthetic Services

The kernel registers familiar Symfony-style core services and the runtime
bridges needed for WordPress:

```text
kernel
service_container
parameter_bag
file_locator
reverse_container
config_cache_factory
dependency_injection.config.container_parameters_resource_checker
config.resource.self_checking_resource_checker
services_resetter
container.env_var_processor
```

It also uses synthetic services for runtime objects that the container must not
construct on its own:

```text
kernel.container
kernel.config
kernel.context
kernel.kernel
kernel.app
```

These IDs are registered as synthetic and public before compilation. After the
runtime container is created, the kernel injects the real instances. Aliases
make those objects injectable by class or interface:

```text
Psr\Container\ContainerInterface
Symfony\Component\DependencyInjection\ContainerInterface
SymPress\Kernel\Container
SymPress\Kernel\SiteConfig
SymPress\Kernel\WpContext
SymPress\Kernel\Kernel\KernelInterface
Symfony\Component\DependencyInjection\Kernel\KernelInterface
SymPress\Kernel\App
Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface
Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface
Symfony\Component\DependencyInjection\ServicesResetterInterface
```

```php
use SymPress\Kernel\SiteConfig;
use SymPress\Kernel\WpContext;

final class ContextAwareService
{
    public function __construct(
        private readonly SiteConfig $config,
        private readonly WpContext $context,
    ) {
    }
}
```

This follows Symfony's synthetic service concept and applies it to the bridge
between the compiled container and the running WordPress application.

Services that implement `Symfony\Contracts\Service\ResetInterface` are
autoconfigured with the `kernel.reset` tag and reset through
`services_resetter`. Service subscribers and service locators are also
autoconfigured with Symfony's standard tags.

The core layer also registers Symfony-adjacent services and aliases:
`filesystem`, optional `event_dispatcher`, optional `clock`,
`container.expression_language`, PSR/EventDispatcher aliases,
`LoggerAwareInterface` autoconfiguration, and the usual `container.excluded`
markers for compiler passes, PHP attributes, enums, and PHPUnit test cases.

## Compiler Passes

The kernel registers its own compiler passes and selected Symfony passes:

- `HookCompilerPass`
- `AddConsoleCommandPass`
- `AddBehaviorDescribingTagsPass`
- `ResettableServicePass`
- `MergeExtensionConfigurationPass`
- Bundle-specific passes through `BundleInterface::build()`

`HookCompilerPass` collects all services tagged with `kernel.hook`, validates
method, type, priority, and `accepted_args`, builds a service locator, and sets
the hook metadata on `HookLoader`.

```yaml
services:
    Acme\Demo\Admin\AdminMenu:
        tags:
            - { name: kernel.hook, hook: 'admin_menu', method: register }
```

The pass works on definitions, not service instances. That matters because
compiler passes run before the runtime container exists.

## WordPress Hooks

Hooks stay declarative and container-based.

```yaml
services:
    Acme\Demo\Hook\TextdomainLoader:
        tags:
            - { name: kernel.hook, hook: 'init', method: load }

    Acme\Demo\Hook\ContentFilter:
        tags:
            - { name: kernel.hook, hook: 'the_content', method: filter, type: filter, priority: 20 }
```

Supported tag attributes:

- `hook`: WordPress hook name
- `method`: service method, defaults to `__invoke`
- `type`: `action` or `filter`, defaults to `action`
- `priority`: defaults to `10`
- `accepted_args`: optional; when omitted, the kernel reflects the method
  parameter count

Attributes are validated during compilation. Missing methods, invalid hook
types, and invalid `accepted_args` values fail early.

### `#[AsHook]`

The kernel adds a WordPress-specific attribute:

```php
use SymPress\Kernel\Attribute\AsHook;

#[AsHook('plugins_loaded', priority: 9, acceptedArgs: 0)]
final class PluginBootstrap
{
    public function __invoke(): void
    {
    }
}
```

The attribute can also be placed on a method:

```php
final class AdminNotice
{
    #[AsHook('admin_notices', priority: 5)]
    public function render(): void
    {
    }
}
```

When `method` is not set on a method-level attribute, the kernel uses the name
of the annotated method.

## Console Integration

The kernel registers Symfony Console commands through
`Symfony\Component\Console\Attribute\AsCommand`. The attribute is mapped to the
`console.command` tag through autoconfiguration, and `AddConsoleCommandPass`
collects the commands.

```php
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

#[AsCommand(name: 'demo:report', description: 'Build a demo report.')]
final class DemoReportCommand extends Command
{
}
```

`WpCliConsoleBridge` exposes the command through WP-CLI:

```bash
wp console demo:report
```

The kernel also ships small container tools:

```bash
wp console debug:container
wp console debug:container --parameters
wp console lint:container
wp console container:dump --format=yaml
```

## Runtime Cache

The runtime container is written to `var/cache/<environment>/kernel`. The cache
key is based on a fingerprint of:

- project directory
- environment
- debug flag
- deployment fingerprint
- kernel package metadata
- bundle metadata
- loaded configuration files

The source files that can change compiled services are stored in the cache
metadata as a manifest. Production cache hits use the deployment fingerprint as
the normal invalidation boundary and do not stat every source file on every
request. Enable `SYMPRESS_KERNEL_VALIDATE_SOURCE_RESOURCES=1` only for
environments where source-level freshness checks are worth the request-time
filesystem cost.

Loaded configuration resources are tracked separately, including files imported
from PHP/YAML config. Cache hits always validate those config resources so
service wiring changes invalidate the runtime container.

Deployments can include an explicit build identifier to force a new cache
identity:

```bash
SYMPRESS_KERNEL_BUILD_ID=2026-06-13T120000Z
```

Operational notes:

- Production deployments should clear `var/cache/<environment>/kernel` or set a
  new `SYMPRESS_KERNEL_BUILD_ID` when bundle PHP source changes are deployed.
- `SYMPRESS_KERNEL_VALIDATE_SOURCE_RESOURCES=1` restores source-file mtime/size
  validation on runtime cache hits, but it reintroduces request-time filesystem
  stats for every tracked source resource.
- Configuration files and imported config resources are always validated on
  cache hits because they directly affect service wiring.
- The discovery manifest is safe for normal Composer-based deployments because
  Composer metadata changes invalidate it. Manual edits inside `vendor/` without
  Composer metadata changes require a kernel cache clear.

## Environment Parameters

The kernel exposes only a small allowlist of non-sensitive operational
environment variables as `env.*` container parameters. Secret-looking names such
as `*_SECRET`, `*_TOKEN`, `*_KEY`, `*_PASSWORD`, `*_AUTH`, and `*_SALT` are
never materialized as parameters because compiled containers and container dumps
can be inspected through filesystem or CLI tooling.

Additional non-sensitive names can be allowed through site config:

```php
$config = new EnvConfig();
// KERNEL_ENV_PARAMETERS="APP_PUBLIC_FLAG,APP_BUILD_CHANNEL"
```

Cache files are written with locking so concurrent requests do not dump the same
container at the same time.

## Translations

Bundles can provide `Resources/translations/`. The kernel collects those paths
in `kernel.translation_paths` and registers `TranslationLoader` as a public
service.

Supported files:

```text
*.en.xlf
*.en.xliff
```

The loader reads XLIFF files and groups the results by bundle package.

## Avoid Reaching Into the Container

Symfony recommends keeping application code independent from the container. The
same rule is even more important in the kernel: services should receive their
dependencies through constructors, setters, tagged iterators, or locators.

Allowed container access points are entry points:

- `App::make()` for WordPress glue code
- `HookLoader` for lazy hook services
- small service locators for intentionally limited service sets
- tests and debugging

Avoid this:

```php
final class BadExample
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }
}
```

Prefer this:

```php
final class GoodExample
{
    public function __construct(private readonly MailerInterface $mailer)
    {
    }
}
```

## Debugging

The kernel provides small equivalents to Symfony's container tooling for the
site container:

```bash
wp console debug:container
wp console debug:container app
wp console debug:container --parameters
wp console debug:container --env-vars
wp console debug:container --tag=kernel.hook --types
wp console debug:container app --types --show-arguments
wp console lint:container
wp console container:dump --format=yaml
wp console container:dump --format=xml
wp console container:dump --format=php
```

Useful debug points:

- `kernel.container_configured`, to inspect loaded configuration files.
- `kernel.container_ready`, to inspect the runtime container.
- `var/cache/<env>/kernel/meta.php`, to inspect fingerprint, class, and cache
  file metadata.
- The showcase plugin screen for attributes, locators, tags, and lazy services.

## Symfony Learn More Map

| Symfony topic | Meaning in the kernel |
| --- | --- |
| Compiling the Container | On cache miss, the kernel compiles a runtime container and dumps it with `PhpDumper`. |
| Container Building Workflow | `App::boot()` provides the workflow: discover, configure, compile, dump, hydrate, register hooks. |
| Configurable Bundles | Classic extensions and Symfony 8.1 `BundleExtension` methods with `configure()` and `loadExtension()` are supported. |
| Service Aliases and Public Services | Supported directly; keep public services limited to real entry points. |
| Autowiring | Standard through `_defaults.autowire: true`; resource scans are recommended. |
| Method Calls and Setter Injection | Supported directly, including `#[Required]`. |
| Compiler Passes | Supported directly; bundles can register passes in `build()`. |
| Configurators | Symfony feature, useful for legacy or third-party objects. |
| Debug Container | `debug:container`, `lint:container`, and `container:dump` are available through `wp console`; tag, type, argument, and env-var views are supported. |
| Core Services | `parameter_bag`, `event_dispatcher`, `filesystem`, `clock`, `file_locator`, `reverse_container`, `config_cache_factory`, `services_resetter`, ExpressionLanguage, and env processors are present when the relevant Symfony components are installed. |
| Service Definition Objects | Relevant for the kernel, bundles, and compiler passes. |
| Expressions | Available, but should be used sparingly; factories are usually clearer. |
| Factories | Supported directly and useful for WordPress-adjacent services. |
| Imports | The kernel automatically imports known configuration patterns from the kernel, bundles, and site. |
| Injection Types | Constructor injection is the default; setter and property injection should be deliberate. |
| Lazy Services | Supported directly, including `#[Lazy]`; hook resolution is lazy. |
| Optional Dependencies | Supported directly with optional references. |
| Parent Services | Supported because the container is fully compiled. |
| Request Service | No Symfony `RequestStack` is registered by default; inject `WpContext` for WordPress context. |
| Service Closures | Supported directly; hook callbacks use the same lazy principle. |
| Service Decoration | Supported directly and cleaner than global hooks for service-level extension. |
| Service Subscribers & Locators | Supported directly; the kernel uses service locators for hook services. |
| Non Shared Services | Supported with `shared: false`; use sparingly. |
| Synthetic Services | Central to runtime objects such as App, Kernel, Config, and Context. |
| Service Tags | Central to `kernel.hook`, bundle-specific extension points, and tagged iterators. |

## Sources

- [Symfony DependencyInjection Component](https://symfony.com/doc/current/components/dependency_injection.html)
- [Symfony Service Container](https://symfony.com/doc/current/service_container.html)
- [symfony/dependency-injection Repository](https://github.com/symfony/dependency-injection)
- [Compiling the Container](https://symfony.com/doc/current/components/dependency_injection/compilation.html)
- [Container Building Workflow](https://symfony.com/doc/current/components/dependency_injection/workflow.html)
- [Service Aliases and Public Services](https://symfony.com/doc/current/service_container/alias_private.html)
- [Autowiring](https://symfony.com/doc/current/service_container/autowiring.html)
- [Service Method Calls and Setter Injection](https://symfony.com/doc/current/service_container/calls.html)
- [Compiler Passes](https://symfony.com/doc/current/service_container/compiler_passes.html)
- [Configurators](https://symfony.com/doc/current/service_container/configurators.html)
- [Debugging the Service Container](https://symfony.com/doc/current/service_container/debug.html)
- [Service Definition Objects](https://symfony.com/doc/current/service_container/definitions.html)
- [Expression Language](https://symfony.com/doc/current/service_container/expression_language.html)
- [Factories](https://symfony.com/doc/current/service_container/factories.html)
- [Imports](https://symfony.com/doc/current/service_container/import.html)
- [Types of Injection](https://symfony.com/doc/current/service_container/injection_types.html)
- [Lazy Services](https://symfony.com/doc/current/service_container/lazy_services.html)
- [Optional Dependencies](https://symfony.com/doc/current/service_container/optional_dependencies.html)
- [Parent Services](https://symfony.com/doc/current/service_container/parent_services.html)
- [Request from the Container](https://symfony.com/doc/current/service_container/request.html)
- [Service Closures](https://symfony.com/doc/current/service_container/service_closures.html)
- [Service Decoration](https://symfony.com/doc/current/service_container/service_decoration.html)
- [Service Subscribers & Locators](https://symfony.com/doc/current/service_container/service_subscribers_locators.html)
- [Non Shared Services](https://symfony.com/doc/current/service_container/shared.html)
- [Synthetic Services](https://symfony.com/doc/current/service_container/synthetic_services.html)
- [Service Tags](https://symfony.com/doc/current/service_container/tags.html)
