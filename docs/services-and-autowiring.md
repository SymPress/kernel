# Services and Autowiring

The kernel delegates normal service wiring to Symfony DependencyInjection.
Autowiring, named aliases, `#[Autowire]`, `#[Target]`, `#[Required]`, tagged
iterators, and service locators all use Symfony's native behavior when classes
are loaded through a service resource scan.

## Recommended Service Resource

Place bundle services in `Resources/config/services.yaml` and scan the bundle
source tree:

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

This mirrors the Symfony skeleton: constructor type declarations are resolved
automatically, autoconfiguration tags known interfaces and attributes, and
services stay private unless they are deliberate entry points.

Class attributes such as `#[When]`, `#[WhenNot]`, `#[Autoconfigure]`,
`#[AutoconfigureTag]`, `#[AutoconfigureResourceTag]`, `#[Exclude]`,
`#[AutowireIterator]`, `#[AutowireLocator]`, and `#[AsTagDecorator]` are applied
when Symfony discovers the class during the resource scan.

## Basic Autowiring

Autowiring reads constructor types and injects matching services:

```php
namespace Acme\Demo\Admin;

final class SettingsPage
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly NoticeRenderer $notices,
    ) {
    }
}
```

If `SettingsRepository` and `NoticeRenderer` are registered services, Symfony
can build `SettingsPage` without an explicit argument list.

## Interfaces and Aliases

When an argument is type-hinted with an interface, define the default
implementation as an alias:

```yaml
services:
    Acme\Demo\Contract\FormatterInterface:
        alias: Acme\Demo\Formatter\HtmlFormatter
```

```php
use Acme\Demo\Contract\FormatterInterface;

final class ReportRenderer
{
    public function __construct(
        private readonly FormatterInterface $formatter,
    ) {
    }
}
```

## Multiple Implementations and `#[Target]`

Use named autowiring aliases when several services implement the same
interface:

```yaml
services:
    'Acme\Demo\Contract\FormatterInterface $adminFormatter':
        alias: Acme\Demo\Formatter\HtmlFormatter

    'Acme\Demo\Contract\FormatterInterface $apiFormatter':
        alias: Acme\Demo\Formatter\JsonFormatter
```

Use `#[Target]` to select the named alias. In Symfony 8.1 and newer, relying
only on the parameter name for named autowiring aliases is deprecated:

```php
use Acme\Demo\Contract\FormatterInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

final class FormatterSelection
{
    public function __construct(
        #[Target('adminFormatter')]
        private readonly FormatterInterface $admin,
        #[Target('apiFormatter')]
        private readonly FormatterInterface $api,
    ) {
    }
}
```

The value passed to `#[Target]` is the named alias target, not a service ID.

## Scalar Values and `#[Autowire]`

Symfony cannot infer scalar values. Configure them in YAML:

```yaml
parameters:
    demo.message: 'Hello from the kernel'

services:
    Acme\Demo\Service\MessagePrinter:
        arguments:
            $message: '%demo.message%'
```

Or attach the value to the constructor argument with `#[Autowire]`:

```php
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class MessagePrinter
{
    public function __construct(
        #[Autowire(param: 'demo.message')]
        private readonly string $message,
    ) {
    }
}
```

Prefer YAML for shared package policy. Prefer `#[Autowire]` when the value is a
small, local detail of the consuming service.

## Method Calls and `#[Required]`

Constructor injection should be the default. Use setter injection for optional
or post-construction dependencies and mark the method with `#[Required]`:

```php
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\Attribute\Required;

final class RequiredSummary
{
    private ?LoggerInterface $logger = null;

    #[Required]
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}
```

The YAML equivalent is:

```yaml
services:
    Acme\Demo\Service\RequiredSummary:
        calls:
            - setLogger: ['@logger']
```

## Tagged Collections

Tags are the standard way to model package extension points:

```php
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('demo.panel')]
interface PanelInterface
{
}
```

```php
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'primary', priority: 20)]
final class PrimaryPanel implements PanelInterface
{
}
```

Inject the collection with `#[AutowireIterator]`:

```php
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class PanelRegistry
{
    public function __construct(
        #[AutowireIterator('demo.panel', indexAttribute: 'index')]
        private readonly iterable $panels,
    ) {
    }
}
```

`indexAttribute` uses the tag attribute as the iterable key. If no explicit
index is present, Symfony falls back to numeric keys unless another index
strategy is configured.

## Service Locators

Use service locators for lazy access to a small, explicit set of services:

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

This replaces array-based pseudo services with real Symfony locators. Inject
`Psr\Container\ContainerInterface` for these small locators instead of injecting
the whole application container.

YAML remains available for the same idea:

```yaml
services:
    Acme\Demo\Service\FormatterLocator:
        arguments:
            $formatters: !service_locator
                html: '@Acme\Demo\Formatter\HtmlFormatter'
                json: '@Acme\Demo\Formatter\JsonFormatter'
```

## Explicit Definitions Still Matter

Autowiring covers normal object graphs, but explicit definitions are still the
right tool for:

- aliases and named autowiring aliases
- scalar arguments and complex `bind` rules
- factories and configurators
- public entry-point services
- WordPress hook tags and package-specific tags
- service locators and tagged iterators in YAML
