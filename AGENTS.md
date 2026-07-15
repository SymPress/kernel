# SymPress Kernel agent contract

## Purpose and boundaries

This package owns the shared WordPress application container, bundle discovery, boot lifecycle, hook/route compilation and production container cache. Its public surface has high cross-repository impact; preserve compatibility unless a breaking release is intended.

## Read first

- `docs/README.md`: mental model and focused documentation routes.
- `docs/boot-and-bundles.md`: boot chain, discovery and config precedence.
- `docs/dependency-injection.md`: container build, extensions and runtime cache.
- `resources/kernel-contracts.json`: checked public entry points, actions and inspection commands.
- `schema/kernel-extra.schema.json`: Composer `extra.kernel` contract.

## Verification

- Fast: `composer tests -- --filter <changed subsystem>`.
- Full: `composer qa`.
- For container changes, also exercise `lint:container`; use `debug:container` or `container:dump --format=yaml` for runtime inspection rather than committing a static service graph.

## Invariants

- One shared container per site; WordPress hooks remain the runtime integration boundary.
- Configuration precedence stays kernel defaults, bundle config, then site config.
- Keep services private unless they are deliberate entry points.
- Discovery/cache fingerprints must invalidate for the metadata and config they consume without scanning all source on every production request.
- Never expose secrets through container dumps, environment parameters or committed fixtures.
- Keep `resources/kernel-contracts.json` and the `extra.kernel` schema synchronized with source.

## Cross-repository impact

Starter, demo, framework-bundle and most SymPress application packages consume this runtime. Changes to lifecycle actions, Composer metadata, discovery, aliases or public container services require representative consumer validation in addition to package tests.

## Definition of done

Focused and full QA pass, the contract inventory test covers surface changes, cache/error paths are tested, docs/schema match behavior, and a high-impact change has downstream canary evidence.
