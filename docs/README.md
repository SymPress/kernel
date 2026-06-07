# Kernel Docs

`sympress/kernel` is a WordPress kernel built on `symfony/dependency-injection`.
It boots exactly one global site-level container, discovers active MU plugins, plugins, and themes as bundles, and imports their `config/` directories into that same container.

## Contents

- `boot-and-bundles.md`
- `services-and-autowiring.md`
- `attributes.md`
- `hooks.md`
- `showcase-plugin.md`

## Guiding Principles

- bundles instead of service providers
- a global Symfony container instead of isolated plugin containers
- `config/services.yaml` as the primary source of truth
- attributes only where they usefully complement YAML definitions
- declarative WordPress hooks through `kernel.hook` or `#[AsHook]`
