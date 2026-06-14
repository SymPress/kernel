# Attributes

The kernel only adds attributes where WordPress needs its own bridge. Most
attribute behavior comes directly from Symfony and works through the normal
DependencyInjection component.

Attributes are applied when services are loaded through resource scans. Explicit
service definitions remain valid, but Symfony only sees class attributes for
classes that are scanned or otherwise reflected by the container loader.

## Supported Symfony DI Attributes

These attributes are supported from Symfony's DependencyInjection component:

- `#[Autowire]`
- `#[AutowireCallable]`
- `#[AutowireDecorated]`
- `#[AutowireInline]`
- `#[AutowireIterator]`
- `#[AutowireLocator]`
- `#[AutowireMethodOf]`
- `#[AutowireServiceClosure]`
- `#[AsAlias]`
- `#[AsDecorator]`
- `#[AsTagDecorator]`
- `#[AsTaggedItem]`
- `#[Autoconfigure]`
- `#[AutoconfigureTag]`
- `#[AutoconfigureResourceTag]`
- `#[Exclude]`
- `#[Lazy]`
- `#[Required]`
- `#[Target]`
- `#[When]`
- `#[WhenNot]`

The kernel also supports Symfony's kernel attribute:

- `#[RequiredBundle]`

## WordPress-Specific Attributes

- `#[AsHook]`
- `#[Route]`

`#[AsHook]` declares WordPress actions and filters on services. `#[Route]`
follows Symfony's route attribute shape for controller services. Routes with
`format: 'json'` are registered as WordPress REST endpoints. Routes without
`format: 'json'` are matched as frontend routes. See `routing.md` for route
details.

## Service Resource Requirement

Use a resource scan so Symfony can discover classes and read their attributes:

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

Do not exclude interfaces or abstract classes that carry
`#[AutoconfigureTag]`; Symfony needs to see them to apply the tag metadata.

## Autowiring Attributes

Use `#[Autowire]` for scalar values or special arguments Symfony cannot infer
from a type declaration:

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

Use `#[Target]` to select a named autowiring alias when multiple services
implement the same interface:

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

In Symfony 8.1 and newer, relying only on a constructor parameter name to match
a named autowiring alias is deprecated. The value passed to `#[Target]` is the
named alias target, not a service ID.

The named aliases live in service configuration:

```yaml
services:
    'Acme\Demo\Contract\FormatterInterface $adminFormatter':
        alias: Acme\Demo\Formatter\HtmlFormatter

    'Acme\Demo\Contract\FormatterInterface $apiFormatter':
        alias: Acme\Demo\Formatter\JsonFormatter
```

## Method Calls

Use `#[Required]` for targeted setter or method injection. Constructor
injection remains the default for required dependencies.

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

## Tagged Collections

Use `#[AutoconfigureTag]` to declare extension-point tags and
`#[AsTaggedItem]` to provide tag metadata:

```php
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('demo.panel')]
interface PanelInterface
{
}

#[AsTaggedItem(index: 'primary', priority: 20)]
final class PrimaryPanel implements PanelInterface
{
}
```

Use `#[AutowireIterator]` to inject the tagged services:

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

Use `#[AutowireLocator]` for a small PSR-11 locator:

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

Prefer a locator over injecting the whole application container when the service
needs lazy access to a small, explicit set of collaborators.

## Runtime and Environment Attributes

- `#[When]` and `#[WhenNot]` use the current kernel environment. The kernel
  passes that environment to PHP, YAML, INI, glob, directory, and closure
  loaders through Symfony's loader stack.
- `#[Lazy]` is a good fit for expensive services or integrations that should
  not be materialized on every request.
- `#[Exclude]` removes classes from resource service registration.
- `#[AsTagDecorator]` decorates all services with the configured tag.
- `#[AutoconfigureResourceTag]` is intended for compiler passes that call
  `ContainerBuilder::findTaggedResourceIds()`.
- `#[RequiredBundle]` loads bundle dependencies before the bundle that declares
  them.

## WordPress Hook Attribute

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

Method-level hook attributes use the annotated method name when `method` is not
set explicitly:

```php
final class AdminNotice
{
    #[AsHook('admin_notices', priority: 5)]
    public function render(): void
    {
    }
}
```
