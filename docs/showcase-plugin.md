# Showcase Plugin

Das Paket `sympress/kernel-showcase` ist ein Demo-Plugin fuer den Kernel.

## Installation im Projekt

Das Root-Projekt bindet es als `require-dev` ein. Danach liegt es als normales WordPress-Plugin unter `public/wp-content/plugins/kernel-showcase`.

Aktivieren:

```bash
ddev wp plugin activate kernel-showcase
```

## Was das Plugin zeigt

- Resource-basiertes Laden aus `config/services.yaml`
- Constructor-Injection per Autowiring
- `#[Autowire(param: ...)]`
- `#[Required]`
- `#[Target]`
- `#[AutowireIterator]`
- `#[AutowireLocator]`
- `#[AsTaggedItem]`
- `#[Autoconfigure]`
- `#[AutoconfigureTag]`
- `#[When]` und `#[WhenNot]`
- `#[Lazy]`
- ein Hook per YAML-Tag
- ein Hook per `#[AsHook]`

## Wo die Demo sichtbar ist

Nach der Aktivierung erscheint unter `Werkzeuge > Kernel Showcase` eine Admin-Seite, die die aufgeloesten DI-Beispiele rendert.

Die Seite zeigt:

- Kernel-Environment und Debug-Status
- Parameter-Injection
- Setter-Injection
- named alias selection per `#[Target]`
- Locator- und Iterator-Injektion
- env-abhaengige Services
- Lazy-Initialisierung
