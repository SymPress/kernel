# Services And Autowiring

## Empfohlenes `services.yaml`

Die empfohlene Struktur orientiert sich am Symfony-Skeleton:

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

Das ist der bevorzugte Weg, damit Symfony-Class-Attributes wie `#[When]`, `#[WhenNot]`, `#[Autoconfigure]` und `#[AutoconfigureTag]` beim Resource-Scan wirklich greifen.

## Wann explizite Definitionen sinnvoll bleiben

- `factory`
- `alias`
- named autowiring aliases
- öffentliche Services
- Spezial-Tags
- komplexe `bind`-Definitionen

Beispiel für named alias + `#[Target]`:

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

Damit ersetzt du Array-Pseudo-Services durch echte Symfony-Locators.
