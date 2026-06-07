# Showcase Plugin

The `sympress/kernel-showcase` package is a demo plugin for the kernel.

## Project Installation

The root project includes it as a `require-dev` dependency. After installation, it is available as a regular WordPress plugin under `public/wp-content/plugins/kernel-showcase`.

Activate it with:

```bash
ddev wp plugin activate kernel-showcase
```

## What the Plugin Demonstrates

- resource-based loading from `config/services.yaml`
- constructor injection through autowiring
- `#[Autowire(param: ...)]`
- `#[Required]`
- `#[Target]`
- `#[AutowireIterator]`
- `#[AutowireLocator]`
- `#[AsTaggedItem]`
- `#[Autoconfigure]`
- `#[AutoconfigureTag]`
- `#[When]` and `#[WhenNot]`
- `#[Lazy]`
- one hook through a YAML tag
- one hook through `#[AsHook]`

## Where the Demo Appears

After activation, an admin page appears under `Tools > Kernel Showcase` and renders the resolved DI examples.

The page shows:

- kernel environment and debug status
- parameter injection
- setter injection
- named alias selection per `#[Target]`
- locator and iterator injection
- environment-dependent services
- lazy initialization
