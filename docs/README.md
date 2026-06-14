# Kernel Docs

`sympress/kernel` is a WordPress kernel built on `symfony/dependency-injection`.
It boots exactly one global site-level container, discovers active MU plugins,
plugins, and themes as bundles, and imports their `Resources/config/`
directories into that same container.

The current kernel targets Symfony DependencyInjection `^8.1`. It keeps the
WordPress-specific pieces small: package discovery, runtime hydration, hook
registration, and WP-CLI bridging. Bundle lifecycle, configurable extensions,
service locators, tagged iterators, resettable services, and most attributes use
the original Symfony component behavior.

## Contents

- `boot-and-bundles.md`
- `services-and-autowiring.md`
- `dependency-injection.md`
- `attributes.md`
- `hooks.md`
- `routing.md`
- `showcase-plugin.md`

## Guiding Principles

- bundles instead of service providers
- a global Symfony container instead of isolated plugin containers
- `Resources/config/services.yaml` as the primary source of truth
- attributes only where they usefully complement YAML definitions
- Symfony DI attributes such as `#[AutowireIterator]` and
  `#[AutowireLocator]` without kernel-specific wrappers
- declarative WordPress hooks through `kernel.hook` or `#[AsHook]`
- controller routes through `#[Route]`, with `format: 'json'` used for
  WordPress REST endpoint registration
