# Hooks

Hooks bleiben deklarativ und containerbasiert.

## YAML-Tags

```yaml
services:
    App\Admin\AdminMenu:
        tags:
            - { name: kernel.hook, hook: 'admin_menu', method: register }
```

Unterstützte Tag-Attribute:

- `hook`
- `method` optional, default `__invoke`
- `type` optional, `action` oder `filter`
- `priority` optional, default `10`
- `accepted_args` optional, wird sonst aus der Methodensignatur abgeleitet

## `#[AsHook]`

Der Kernel bringt `#[AsHook]` als WordPress-spezifisches Attribut mit.

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

Wenn `method` bei einer Methoden-Annotation nicht gesetzt ist, verwendet der Kernel automatisch den annotierten Methodennamen.

## Empfehlung

- YAML für zentrale Registrierungen und offensichtliche Bundle-Struktur
- `#[AsHook]` für kleine, klar abgegrenzte Hook-Klassen
- keine versteckten Hook-Registrierungen in Plugin-Bootstraps oder `functions.php`
