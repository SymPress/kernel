# Attributes

The kernel only extends Symfony where WordPress needs its own bridge. Most attribute behavior comes directly from Symfony.

## Supported Symfony DI Attributes

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

## WordPress-Specific Attribute

- `#[AsHook]`

## Notes

- `#[When]` and `#[WhenNot]` are only useful when services are loaded through resource scans. The kernel passes the current environment to `YamlFileLoader` and `PhpFileLoader`.
- `#[AutoconfigureTag]` on interfaces or abstract classes requires those files to **not** be excluded from the resource scan.
- `#[Required]` is useful for targeted setter or method injection. Constructor injection remains the default.
- `#[Lazy]` is a good fit for expensive services or integrations that should not be materialized on every request.

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
        #[AutowireIterator('app.panel')] private readonly iterable $panels,
    ) {
    }

    #[Required]
    public function setLogger(LoggerInterface $logger): void
    {
    }
}
```
