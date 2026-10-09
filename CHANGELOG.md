# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
where applicable.

## 1.1.8 — 2026-10-09

- Require maintained Symfony Routing and YAML 8.1 or newer, excluding the vulnerable 8.0 releases covered by the May 2026 advisories.
- Require Symfony DependencyInjection 8.1.8 or newer so environment placeholders reused after an earlier extension discards them remain resolvable in compiled containers.
- Verify environment resolution through container creation and cached-container reload with two extensions sharing an overridden environment value.
- Document the native Symfony 8.2 compile-time environment log and add a synthetic availability probe that outputs names and configuration paths only. Stable dependencies remain unchanged; the feature requires the future upstream API.

## 1.1.7 — 2026-10-06

- Isolate native compiler-pass state when rebuilding a runtime container after
  newly discovered resources change its identity. Doctrine event listeners and
  other passes now populate the final container instead of retaining definitions
  from the discarded first candidate.

## 1.1.6 — 2026-10-06 (withdrawn)

- An accidental duplicate of the v1.1.5 source, created before a fetch completed.
  Immutable repository tags retain it for audit. Use v1.1.7 for the compiler fix.

## 1.1.5 — 2026-10-05

- Store container metadata and discovery descriptors as atomic private JSON, so unchanged readers retain OPcache entries while CLI publications remain visible to existing FPM workers with timestamp validation disabled. Ignore and rebuild legacy PHP metadata without executing it.
- Restore automatic private-cache fallback outside served directories for classic WordPress webroots and cold read-only projects; reject pre-created symlinks, foreign owners and writable shared cache roots.

## 1.1.4 — 2026-10-03

- Fail unsafe explicit APP_CACHE_DIR settings with deployment guidance and migrate implicit legacy caches once into visible durable project storage shared by CLI/PHP-FPM.
- Refresh mutable metadata/discovery OPcache reads after atomic replacement while retaining immutable build-ID cache behavior.
- Admit the stable SYMPRESS_PROJECT_DIR cache identity as a safe environment parameter without changing active release discovery.

## 1.1.3 — 2026-10-02

- Default mutable cache validation to mtime/size metadata; keep content hashing as an explicit `SYMPRESS_KERNEL_CONTENT_HASHES=1` mode. Warm container and discovery reads retain OPcache entries.
- Verify upgrade from group-writable caches without executing or modifying prior metadata, discovery or container artifacts.

## 1.1.2 — 2026-10-02

- Validate consumed configuration content once per mutable warm request while preserving same-timestamp edits, imported resources and the explicit immutable build-ID policy.
- Boot existing installations with group-writable compiled-cache directories through a fresh private generation without executing or chmod-adopting prior PHP cache artifacts.

### Added

- Bridge `App::enableDebug()` and `App::disableDebug()` to an optional profiler service.

### Changed

- Split package-manager actions, package-manager rendering, kernel configuration, runtime container cache, and core service registration into focused collaborators.
- Adopt shared SymPress QA tooling and PHP 8.5 package constraints.

### Fixed

- Normalize mixed metadata, route, hook, environment, and WordPress context inputs before using them as typed kernel state.
