# Attributes

Der Kernel ergänzt Symfony nur dort, wo WordPress eine eigene Brücke braucht. Die meiste Attribut-Logik kommt direkt aus Symfony.

## Unterstützte Symfony-DI-Attribute

- `#[Autowire]`
- `#[Required]`
- `#[Target]`
- `#[AutowireIterator]`
- `#[AutowireLocator]`
- `#[AsTaggedItem]`
- `#[When]`
- `#[WhenNot]`
- `#[Autoconfigure]`
- `#[AutoconfigureTag]`
- `#[Lazy]`

## WordPress-spezifisches Attribut

- `#[AsHook]`

## Hinweise

- `#[When]` und `#[WhenNot]` greifen nur sinnvoll, wenn Services per Resource-Scan geladen werden. Der Kernel reicht die aktuelle Environment jetzt an `YamlFileLoader` und `PhpFileLoader` weiter.
- `#[AutoconfigureTag]` auf Interfaces oder abstrakten Klassen setzt voraus, dass diese Dateien **nicht** aus dem Resource-Scan ausgeschlossen werden.
- `#[Required]` ist für punktuelle Setter-/Method-Injection sinnvoll. Constructor-Injection bleibt der Default.
- `#[Lazy]` ist gut für teure Services oder Integrationen, die nicht in jedem Request materialisiert werden sollen.

## Beispiel

```php
#[Lazy]
final class ExpensiveService
{
}

final class DemoService
{
    public function __construct(
        #[Autowire(param: 'app.message')] private readonly string $message,
        #[Target('adminFormatter')] private readonly FormatterInterface $formatter,
        #[AutowireIterator('app.panel')] private readonly iterable $panels,
    ) {
    }

    #[Required]
    public function setLogger(LoggerInterface $logger): void
    {
    }
}
```
