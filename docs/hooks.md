# Hooks

Hooks stay declarative and container-based.

## YAML Tags

```yaml
services:
    App\Admin\AdminMenu:
        tags:
            - { name: kernel.hook, hook: 'admin_menu', method: register }
```

Supported tag attributes:

- `hook`
- `method` optional, defaults to `__invoke`
- `type` optional, `action` or `filter`
- `priority` optional, defaults to `10`
- `accepted_args` optional, otherwise inferred from the method signature

## `#[AsHook]`

The kernel provides `#[AsHook]` as a WordPress-specific attribute.

Class-Level:

```php
#[AsHook('init')]
final class TextdomainLoader
{
    public function __invoke(): void
    {
    }
}
```

Method-Level:

```php
final class AdminNotice
{
    #[AsHook('admin_notices', priority: 5)]
    public function render(): void
    {
    }
}
```

When `method` is not set on a method annotation, the kernel automatically uses the annotated method name.

## Recommendation

- use YAML for central registrations and explicit bundle structure
- use `#[AsHook]` for small, clearly scoped hook classes
- avoid hidden hook registrations in plugin bootstraps or `functions.php`
