# Kernel Docs

`sympress/kernel` ist ein WordPress-Kernel auf Basis von `symfony/dependency-injection`.
Er bootet genau einen globalen Container auf Website-Ebene, entdeckt aktive MU-Plugins, Plugins und Themes als Bundles und importiert deren `config/` in denselben Container.

## Inhalte

- `boot-and-bundles.md`
- `services-and-autowiring.md`
- `attributes.md`
- `hooks.md`
- `showcase-plugin.md`

## Leitidee

- Bundle statt ServiceProvider
- globaler Symfony-Container statt isolierter Plugin-Container
- `config/services.yaml` als Primary Source of Truth
- Attribute nur dort, wo sie die YAML-Definition sinnvoll ergänzen
- WordPress-Hooks deklarativ über `kernel.hook` oder `#[AsHook]`
