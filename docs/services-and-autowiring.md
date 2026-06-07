# Services and Autowiring

## Recommended `services.yaml`

The recommended structure follows the Symfony skeleton:

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true
        public: false

    App\Demo\:
        resource: '../src/'
        exclude:
            - '../src/DemoBundle.php'
```

This is the preferred path so Symfony class attributes such as `#[When]`, `#[WhenNot]`, `#[Autoconfigure]`, and `#[AutoconfigureTag]` are applied during resource scans.

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
        resource: '../src/'
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
        #[AutowireIterator('app.panel')] private readonly iterable $panels,
    ) {
    }
}
```

## Service Locators

```php
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
