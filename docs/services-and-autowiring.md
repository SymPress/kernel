# Services and Autowiring

The kernel lets Symfony DependencyInjection handle normal service wiring. That
includes first-class support for `#[AutowireIterator]` and `#[AutowireLocator]`
when the involved classes are loaded through a service resource scan.

## Recommended `Resources/config/services.yaml`

The recommended structure follows the Symfony skeleton:

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true
        public: false

    App\Demo\:
        resource: '../../src/'
        exclude:
            - '../../src/DemoBundle.php'
```

This is the preferred path so Symfony class attributes such as `#[When]`,
`#[WhenNot]`, `#[Autoconfigure]`, `#[AutoconfigureTag]`,
`#[AutoconfigureResourceTag]`, `#[Exclude]`, `#[AutowireIterator]`,
`#[AutowireLocator]`, and `#[AsTagDecorator]` are applied during resource scans.

## When Explicit Definitions Still Make Sense

- `factory`
- `alias`
- named autowiring aliases
- public services
- special tags
- complex `bind` definitions

Example for named aliases and `#[Target]`:

```yaml
services:
    'App\Contract\FormatterInterface $adminFormatter':
        alias: App\Formatter\HtmlFormatter

    'App\Contract\FormatterInterface $apiFormatter':
        alias: App\Formatter\JsonFormatter
```

```php
public function __construct(
    #[Target('adminFormatter')] private readonly FormatterInterface $adminFormatter,
    #[Target('apiFormatter')] private readonly FormatterInterface $apiFormatter,
) {
}
```

## Tagged Collections

YAML:

```yaml
services:
    App\Contract\PanelInterface:
        resource: '../../src/'
```

Attribute:

```php
#[AutoconfigureTag('app.panel')]
interface PanelInterface
{
}

#[AsTaggedItem(index: 'primary', priority: 20)]
final class PrimaryPanel implements PanelInterface
{
}

final class PanelRegistry
{
    public function __construct(
        #[AutowireIterator('app.panel', indexAttribute: 'index')]
        private readonly iterable $panels,
    ) {
    }
}
```

`indexAttribute` uses the tag attribute as the iterable key. If no explicit
index is present, Symfony falls back to numeric keys unless you configure
another index strategy.

## Service Locators

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

This replaces array-based pseudo services with real Symfony locators.
Inject `Psr\Container\ContainerInterface` for these small, explicit locators
instead of injecting the whole application container.

YAML remains available for the same idea:

```yaml
services:
    App\Service\FormatterLocator:
        arguments:
            $formatters: !service_locator
                html: '@App\Formatter\HtmlFormatter'
                json: '@App\Formatter\JsonFormatter'
```
