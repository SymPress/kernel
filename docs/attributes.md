# Attributes

The kernel only extends Symfony where WordPress needs its own bridge. Most attribute behavior comes directly from Symfony.

## Supported Symfony DI Attributes

- `#[Autowire]`
- `#[AutowireCallable]`
- `#[AutowireDecorated]`
- `#[AutowireInline]`
- `#[Required]`
- `#[Target]`
- `#[AutowireIterator]`
- `#[AutowireLocator]`
- `#[AutowireMethodOf]`
- `#[AutowireServiceClosure]`
- `#[AsAlias]`
- `#[AsDecorator]`
- `#[AsTagDecorator]`
- `#[AsTaggedItem]`
- `#[When]`
- `#[WhenNot]`
- `#[Autoconfigure]`
- `#[AutoconfigureTag]`
- `#[AutoconfigureResourceTag]`
- `#[Exclude]`
- `#[Lazy]`

## Supported Symfony Kernel Attribute

- `#[RequiredBundle]`

## WordPress-Specific Attribute

- `#[AsHook]`

## Notes

- Attributes are applied when services are loaded through resource scans.
  Explicit service definitions remain valid, but Symfony only sees class
  attributes for scanned or otherwise reflected classes.
- `#[When]` and `#[WhenNot]` use the current kernel environment. The kernel
  passes that environment to PHP, YAML, INI, glob, directory, and closure
  loaders through Symfony's loader stack.
- `#[AutoconfigureTag]` on interfaces or abstract classes requires those files to **not** be excluded from the resource scan.
- `#[Required]` is useful for targeted setter or method injection. Constructor injection remains the default.
- `#[Lazy]` is a good fit for expensive services or integrations that should not be materialized on every request.
- `#[Exclude]` removes classes from resource service registration.
- `#[AsTagDecorator]` decorates all services with the configured tag.
- `#[AutoconfigureResourceTag]` is intended for compiler passes that call
  `ContainerBuilder::findTaggedResourceIds()`.
- `#[RequiredBundle]` loads bundle dependencies before the bundle that declares
  them.
- `#[AutowireIterator]` injects a lazy iterable of tagged services.
- `#[AutowireLocator]` injects a small PSR service locator; prefer it over
  injecting the whole application container.

## Example

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
        #[AutowireIterator('app.panel', indexAttribute: 'index')]
        private readonly iterable $panels,
        #[AutowireLocator([
            'html' => HtmlFormatter::class,
            'json' => JsonFormatter::class,
        ])]
        private readonly \Psr\Container\ContainerInterface $formatters,
    ) {
    }

    #[Required]
    public function setLogger(LoggerInterface $logger): void
    {
    }
}
```
